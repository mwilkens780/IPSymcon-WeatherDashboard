<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileKit.php';

class WeatherDashboard extends IPSModule
{
    use \Fuchsbau\TileKit\WeatherDashboard\TileKit;

    // ─── Weather Channel icon code (0-47) → short German label ────────────────
    // Standard, publicly documented icon set used by the Weather Company / WU
    // API (and by the WundergroundPWSSync module's bundled icon PNGs).
    private const ICON_LABELS = [
        0 => 'Tornado', 1 => 'Tropensturm', 2 => 'Hurrikan', 3 => 'Starke Gewitter',
        4 => 'Gewitter', 5 => 'Regen und Schnee', 6 => 'Regen und Graupel', 7 => 'Schnee und Graupel',
        8 => 'Gefrierender Nieselregen', 9 => 'Nieselregen', 10 => 'Gefrierender Regen', 11 => 'Schauer',
        12 => 'Schauer', 13 => 'Schneeschauer', 14 => 'Leichte Schneeschauer', 15 => 'Schneetreiben',
        16 => 'Schnee', 17 => 'Hagel', 18 => 'Schneeregen', 19 => 'Staub',
        20 => 'Nebel', 21 => 'Dunst', 22 => 'Rauchig', 23 => 'Stürmisch',
        24 => 'Windig', 25 => 'Kalt', 26 => 'Bewölkt', 27 => 'Stark bewölkt',
        28 => 'Stark bewölkt', 29 => 'Teilweise bewölkt', 30 => 'Teilweise bewölkt', 31 => 'Klar',
        32 => 'Sonnig', 33 => 'Heiter', 34 => 'Heiter', 35 => 'Regen und Hagel',
        36 => 'Heiß', 37 => 'Vereinzelt Gewitter', 38 => 'Vereinzelt Gewitter', 39 => 'Vereinzelt Gewitter',
        40 => 'Vereinzelt Schauer', 41 => 'Starker Schnee', 42 => 'Vereinzelt Schneeschauer', 43 => 'Starker Schnee',
        44 => 'Teilweise bewölkt', 45 => 'Schauer mit Gewitter', 46 => 'Schneeschauer', 47 => 'Vereinzelt Gewitter',
    ];

    private const ICON_DIR = 'modules/.store/elueckel.wundergroundpwssync/WundergroundPWSSync/icons/';

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('update_interval', 300);

        // Defaults pre-filled with this installation's actual object IDs
        // (category "Wetter", 56501) so the module works out of the box;
        // still overridable via the config form's SelectVariable pickers.
        $this->RegisterPropertyInteger('var_temp',           39485); // ACTUAL_TEMPERATURE
        $this->RegisterPropertyInteger('var_humidity',       56259); // HUMIDITY
        $this->RegisterPropertyInteger('var_wind_speed',     55831); // WIND_SPEED
        $this->RegisterPropertyInteger('var_wind_dir',       45273); // WIND_DIR
        $this->RegisterPropertyInteger('var_illumination',   25842); // ILLUMINATION
        $this->RegisterPropertyInteger('var_rain_now',       22316); // RAIN (bool)
        $this->RegisterPropertyInteger('var_sun_now',        48188); // SUNSHINE_THRESHOLD_OVERRUN (bool)
        $this->RegisterPropertyInteger('var_sunshine_today', 33698); // SonnenscheinMinuten
        $this->RegisterPropertyInteger('var_rain_today',     41303); // RegenHeute

        $this->RegisterPropertyInteger('warning_instance',  38412); // Unwetterwarnung (Weather Warning)
        $this->RegisterPropertyInteger('forecast_instance', 57338); // Weather.com (WundergroundPWSSync)
        $this->RegisterPropertyInteger('forecast_segments', 6);

        $this->RegisterPropertyInteger('archive_instance', 59233); // Archive Control
        $this->RegisterPropertyInteger('pollen_instance',  52629); // Pollenflug (Pollen Count)

        $this->RegisterPropertyFloat('latitude',  0.0);
        $this->RegisterPropertyFloat('longitude', 0.0);

        $this->RegisterTimer('UpdateTimer', 0, 'WXD_Refresh($_IPS[\'TARGET\']);');

        // HTML-SDK dashboard tile
        $this->SetVisualizationType(1);
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyInteger('var_temp') === 0) {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);

        $this->Refresh();
    }

    // ─── HTML-SDK: dashboard tile ──────────────────────────────────────────────

    public function GetVisualizationTile(): string
    {
        return $this->buildDashboardHTML();
    }

    // ─── Public update ────────────────────────────────────────────────────────

    /**
     * Re-reads all configured source variables and pushes fresh values to any
     * already-open tile. This module owns no variables of its own — everything
     * is read live from the Homematic weather station / Weather Warning /
     * Weather.com instances, so "refresh" is purely a read + push, no API
     * calls, safe to run frequently.
     */
    public function Refresh(): void
    {
        try {
            $this->tkPush('__all__', $this->view($this->collectData()));
            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('WeatherDashboard Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /** Reads a variable's value, or null if the configured ID is 0/invalid. */
    private function readVar(string $prop)
    {
        $id = $this->ReadPropertyInteger($prop);
        if ($id <= 0 || !@IPS_VariableExists($id)) {
            return null;
        }
        return GetValue($id);
    }

    /** Reads a child variable of a foreign instance by ident, or null if missing. */
    private function readForeign(int $instanceID, string $ident)
    {
        if ($instanceID <= 0) {
            return null;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $instanceID);
        if ($id === false) {
            return null;
        }
        return GetValue($id);
    }

    /** 16-point compass label for a wind direction in degrees. */
    private function compassText(?float $deg): string
    {
        if ($deg === null) {
            return '–';
        }
        $dirs = ['N', 'NNO', 'NO', 'ONO', 'O', 'OSO', 'SO', 'SSO', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        $norm = fmod($deg, 360);
        if ($norm < 0) {
            $norm += 360;
        }
        $idx = ((int) round($norm / 22.5)) % 16;
        return $dirs[$idx];
    }

    /** Base64 data-URI for a bundled Weather Channel icon PNG, or '' if not found. */
    private function iconDataUri(?int $code): string
    {
        if ($code === null || $code < 0 || $code > 47) {
            return '';
        }
        $path = IPS_GetKernelDir() . self::ICON_DIR . $code . '.png';
        if (!is_file($path)) {
            return '';
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return '';
        }
        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    private function iconLabel(?int $code): string
    {
        if ($code === null) {
            return '–';
        }
        return self::ICON_LABELS[$code] ?? '–';
    }

    /** Today's sunrise/sunset as HH:MM, computed astronomically (no external dependency). */
    private function sunTimes(): array
    {
        $lat = $this->ReadPropertyFloat('latitude');
        $lon = $this->ReadPropertyFloat('longitude');
        $info = date_sun_info(time(), $lat, $lon);
        $fmt = static function ($ts) {
            return is_int($ts) ? date('H:i', $ts) : '–';
        };
        return [
            'sunrise' => $fmt($info['sunrise']),
            'sunset'  => $fmt($info['sunset']),
        ];
    }

    private function pollenText(): ?string
    {
        $instanceID = $this->ReadPropertyInteger('pollen_instance');
        if ($instanceID <= 0) {
            return null;
        }
        $id = @IPS_GetObjectIDByIdent('Hint', $instanceID);
        if ($id === false) {
            return null;
        }
        $text = trim((string) GetValue($id));
        return $text !== '' ? $text : null;
    }

    private const CHART_SPAN_SEC = 6 * 3600; // 6h — 24h was too flat/unreadable

    /**
     * Last CHART_SPAN_SEC of raw archived temperature readings as
     * [[unixTimestamp, value], ...], oldest first, for the SVG sparkline.
     * Empty if the variable isn't archived.
     */
    private function temperatureHistory(): array
    {
        $archiveID = $this->ReadPropertyInteger('archive_instance');
        $tempID    = $this->ReadPropertyInteger('var_temp');
        if ($archiveID <= 0 || $tempID <= 0 || !function_exists('AC_GetLoggedValues')) {
            return [];
        }

        $now = time();
        try {
            $rows = @AC_GetLoggedValues($archiveID, $tempID, $now - self::CHART_SPAN_SEC, $now, 0);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $points = [];
        foreach (array_reverse($rows) as $row) { // AC_GetLoggedValues returns newest-first
            $points[] = [(int) $row['TimeStamp'], (float) $row['Value']];
        }
        return $points;
    }

    /**
     * SVG polyline points plus the (unpadded) real min/max, so the caller can
     * render an actual readable numeric scale next to the curve.
     */
    private function tempChartData(array $history, float $viewW = 300.0, float $viewH = 70.0): array
    {
        if (count($history) < 2) {
            return ['points' => '', 'min' => null, 'max' => null];
        }

        $startTs   = time() - self::CHART_SPAN_SEC;
        $values    = array_column($history, 1);
        $realMin   = min($values);
        $realMax   = max($values);
        $scaleMin  = $realMin;
        $scaleMax  = $realMax;
        if ($scaleMax - $scaleMin < 1.0) {
            $mid      = ($scaleMax + $scaleMin) / 2;
            $scaleMin = $mid - 0.5;
            $scaleMax = $mid + 0.5;
        }
        $pad = ($scaleMax - $scaleMin) * 0.15;
        $scaleMin -= $pad;
        $scaleMax += $pad;

        $pts = [];
        foreach ($history as [$ts, $val]) {
            $x = max(0, min($viewW, ($ts - $startTs) / self::CHART_SPAN_SEC * $viewW));
            $y = $viewH - (($val - $scaleMin) / ($scaleMax - $scaleMin)) * $viewH;
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        return ['points' => implode(' ', $pts), 'min' => $realMin, 'max' => $realMax];
    }

    /**
     * Gathers every value the tile needs into one flat array — used both for
     * the initial PHP render and as the payload pushed to already-open tiles,
     * so both code paths always agree on structure.
     */
    private function collectData(): array
    {
        $warningInstance  = $this->ReadPropertyInteger('warning_instance');
        $forecastInstance = $this->ReadPropertyInteger('forecast_instance');
        $segments         = max(1, min(6, $this->ReadPropertyInteger('forecast_segments')));

        // Group the 12h day/night segments the forecast API provides into one
        // card per calendar day, with two labeled halves ("Tag"/"Nacht" — the
        // API's own dayOrNight flag, not a guessed morning/afternoon split).
        //
        // The API returns no data at all for a day-segment that's already
        // underway (e.g. "today daytime" once it's past sunrise) — its
        // Name/DN fields come back empty while Icon/Temperature simply never
        // get touched and stay frozen at whatever was last cached (which can
        // be weeks old). Name emptiness is therefore used as the "is this
        // slot's data actually current" signal, rather than trusting a
        // present-but-stale Icon/Temperature value.
        $days = [];
        for ($i = 0; $i < $segments; $i += 2) {
            $dayIndex = intdiv($i, 2);
            $dayTs    = time() + $dayIndex * 86400;
            $dayLabel = $dayIndex === 0 ? 'Heute' : ($dayIndex === 1 ? 'Morgen' : ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int) date('w', $dayTs)] . ', ' . date('d.m.', $dayTs));

            $halves = [];
            for ($seg = $i; $seg <= $i + 1 && $seg < $segments; $seg++) {
                $name      = $this->readForeign($forecastInstance, "DP{$seg}Name");
                $dn        = $this->readForeign($forecastInstance, "DP{$seg}DN");
                $available = $name !== null && trim((string) $name) !== '';

                $label = match ((string) $dn) {
                    'D'     => 'Tag',
                    'N'     => 'Nacht',
                    default => $seg % 2 === 0 ? 'Tag' : 'Nacht', // fallback if DN itself is unavailable
                };

                $icon = $available ? $this->readForeign($forecastInstance, "DP{$seg}Icon") : null;
                $temp = $available ? $this->readForeign($forecastInstance, "DP{$seg}Temperature") : null;

                $halves[] = [
                    'label' => $label,
                    'icon'  => $icon !== null ? (int) $icon : null,
                    'temp'  => $temp !== null ? (float) $temp : null,
                    'desc'  => $available ? $this->iconLabel($icon !== null ? (int) $icon : null) : 'Keine Daten',
                ];
            }

            $days[] = ['label' => $dayLabel, 'halves' => $halves];
        }

        $warnLevel = $this->readForeign($warningInstance, 'Level');
        $warnText  = $this->readForeign($warningInstance, 'Text');
        $warnTable = $this->readForeign($warningInstance, 'Table');
        $radarHtml = $this->readForeign($warningInstance, 'MovRadar');
        $sun       = $this->sunTimes();

        return [
            'temp'          => $this->readVar('var_temp'),
            'humidity'      => $this->readVar('var_humidity'),
            'windSpeed'     => $this->readVar('var_wind_speed'),
            'windDir'       => $this->readVar('var_wind_dir'),
            'illumination'  => $this->readVar('var_illumination'),
            'rainNow'       => $this->readVar('var_rain_now'),
            'sunNow'        => $this->readVar('var_sun_now'),
            'sunshineToday' => $this->readVar('var_sunshine_today'),
            'rainToday'     => $this->readVar('var_rain_today'),
            'sunrise'       => $sun['sunrise'],
            'sunset'        => $sun['sunset'],
            'pollen'        => $this->pollenText(),
            'tempHistory'   => $this->temperatureHistory(),
            'warnLevel'     => $warnLevel !== null ? (int) $warnLevel : null,
            'warnText'      => $warnText,
            'warnTable'     => $warnTable,
            'radarHtml'     => $radarHtml,
            'days'          => $days,
            'updated'       => date('d.m. H:i'),
        ];
    }

    // ─── Rendering (TileKit, siehe docs/ui-kachel.md im Workspace) ─────────────

    private static function num(?float $v, int $digits, string $suffix): string
    {
        return $v === null ? '–' : number_format($v, $digits, ',', '') . $suffix;
    }

    /**
     * Alle Anzeige-Texte an EINER Stelle – Erstrender und Live-Push nutzen
     * dasselbe Ergebnis, das JS setzt nur noch ein (frueher doppelt in PHP und JS).
     */
    private function view(array $d): array
    {
        $f        = static fn ($v) => $v === null ? null : (float) $v;
        $rainNow  = (bool) $d['rainNow'];
        $sunNow   = (bool) $d['sunNow'];
        $sunMin   = $d['sunshineToday'] !== null ? (int) round((float) $d['sunshineToday']) : null;
        $level    = $d['warnLevel'];
        $hasWarn  = $level !== null && $level > 0;
        $chart    = $this->tempChartData($d['tempHistory']);

        return [
            'updated'  => $d['updated'],
            'temp'     => self::num($f($d['temp']), 1, ' °C'),
            'chart'    => $chart['points'],
            'chartMax' => $chart['max'] !== null ? self::num($chart['max'], 1, '°') : '',
            'chartMin' => $chart['min'] !== null ? self::num($chart['min'], 1, '°') : '',
            'humidity' => $d['humidity'] === null ? '–' : round((float) $d['humidity']) . ' %',
            'illum'    => $d['illumination'] === null ? '–' : number_format((float) $d['illumination'], 0, ',', '.') . ' lx',
            'wind'     => self::num($f($d['windSpeed']), 1, ' km/h'),
            'windDir'  => $d['windDir'] === null ? '' : 'aus ' . $this->compassText((float) $d['windDir']),
            'sun'      => ($d['sunrise'] ?: '–') . ' – ' . ($d['sunset'] ?: '–'),
            'sunshine' => $sunMin === null ? '–' : sprintf('%d h %02d min', intdiv($sunMin, 60), $sunMin % 60),
            'rainToday' => self::num($f($d['rainToday']), 1, ' mm'),
            'rainNow'  => ['text' => $rainNow ? 'Regnet' : 'Trocken', 'icon' => $rainNow ? '🌧️' : '⛅', 'state' => $rainNow ? 'accent' : ''],
            'sunNow'   => ['text' => $sunNow ? 'Sonne' : 'Keine Sonne', 'icon' => $sunNow ? '☀️' : '☁️', 'state' => $sunNow ? 'ok' : ''],
            'pollen'   => ['text' => $d['pollen'] ?? 'Keine Daten', 'icon' => '🌼', 'state' => ''],
            // Stufe 1/2 gelb/orange = Warnfarbe, 3/4 rot/violett = ganze Kachel rot
            'warn'     => [
                'text'  => $d['warnText'] !== null && $d['warnText'] !== '' ? (string) $d['warnText'] : 'Keine Warnung',
                'icon'  => $hasWarn ? '⚠️' : '✅',
                'state' => !$hasWarn ? 'ok' : ($level >= 3 ? 'alarm' : 'warn'),
            ],
            'warnTable' => $hasWarn && $d['warnTable'] !== null ? (string) $d['warnTable'] : '',
            'radar'    => $d['radarHtml'] !== null ? (string) $d['radarHtml'] : '',
            'forecast' => $this->forecastHtml($d['days']),
        ];
    }

    /** Eine Kachel je Tag mit Tag-/Nacht-Haelfte (Icon, Temperatur, Beschreibung). */
    private function forecastHtml(array $days): string
    {
        $html = '';
        foreach ($days as $day) {
            $halves = '';
            foreach ($day['halves'] as $half) {
                $uri    = $this->iconDataUri($half['icon']);
                $img    = $uri !== '' ? "<img src=\"{$uri}\" alt=\"\">" : '<span class="fc-noicon">–</span>';
                $temp   = $half['temp'] !== null ? round($half['temp']) . '°' : '–';
                $halves .= '<div class="fc-half"><span class="tk-sub">' . self::tkEsc($half['label']) . "</span>{$img}"
                    . "<span class=\"fc-temp\">{$temp}</span><span class=\"tk-sub fc-desc\">" . self::tkEsc($half['desc']) . '</span></div>';
            }
            $html .= '<div class="tk-tile fc-day"><span class="tk-name">' . self::tkEsc($day['label']) . "</span><div class=\"fc-halves\">{$halves}</div></div>";
        }
        return $html;
    }

    private function buildDashboardHTML(): string
    {
        $v = $this->view($this->collectData());

        $chart = $v['chart'] !== ''
            ? '<div class="tk-chart temp-chart"><svg viewBox="0 0 300 70" preserveAspectRatio="none"><polyline id="temp_poly" points="' . $v['chart'] . '"/></svg>'
                . '<span id="chart_max" class="chart-max">' . $v['chartMax'] . '</span><span id="chart_min" class="chart-min">' . $v['chartMin'] . '</span>'
                . '<div class="chart-x"><span>-6h</span><span>-3h</span><span>jetzt</span></div></div>'
            : '';

        $body  = '<div class="tk-grid">';
        $body .= self::tkStatusTile('warn', 'Wetterwarnung', $v['warn']['text'], $v['warn']['icon'], $v['warn']['state'], null, '', 'tk-span2');
        $body .= self::tkGroup('Aktuell');
        $body .= '<div class="tk-tile tk-span2" id="temp_card"><span class="tk-name">Temperatur</span><span id="temp_val" class="tk-value">' . self::tkEsc($v['temp']) . "</span>{$chart}</div>";
        $body .= self::tkValueTile('hum', 'Luftfeuchte', $v['humidity']);
        $body .= '<div class="tk-tile" id="wind_card"><span class="tk-name">Wind</span><span id="wind_val" class="tk-value">' . self::tkEsc($v['wind'])
            . '</span><span id="wind_sub" class="tk-sub">' . self::tkEsc($v['windDir']) . '</span></div>';
        $body .= self::tkValueTile('illum', 'Helligkeit', $v['illum']);
        $body .= self::tkStatusTile('rain_now', 'Regen', $v['rainNow']['text'], $v['rainNow']['icon'], $v['rainNow']['state']);
        $body .= self::tkStatusTile('sun_now', 'Sonnenschein', $v['sunNow']['text'], $v['sunNow']['icon'], $v['sunNow']['state']);
        $body .= self::tkValueTile('sunshine', 'Sonne heute', $v['sunshine']);
        $body .= self::tkValueTile('rain_today', 'Regen heute', $v['rainToday']);
        $body .= self::tkValueTile('sun', 'Sonne auf / unter', $v['sun'], '', 'sun-times');
        $body .= self::tkStatusTile('pollen', 'Pollenflug', $v['pollen']['text'], $v['pollen']['icon']);
        $body .= '<div id="warn_table_wrap" class="tk-full"' . ($v['warnTable'] === '' ? ' hidden' : '') . '>' . self::tkHtmlTile('warn_table', 'Warnmeldungen', $v['warnTable'], 140) . '</div>';
        $body .= self::tkGroup('Vorhersage');
        $body .= '<div id="forecast" class="fc-wrap">' . $v['forecast'] . '</div>';
        if ($v['radar'] !== '') {
            $body .= self::tkGroup('Regenradar');
            $body .= self::tkHtmlTile('radar', 'Regenradar', $v['radar'], 260);
        }
        $body .= '</div>';

        $css = <<<'CSS'
[hidden]{display:none !important}
.temp-chart{position:relative;margin-top:2px}
.temp-chart svg{height:46px}
.chart-max,.chart-min{position:absolute;right:2px;font-size:var(--tk-fs-meta);color:var(--tk-accent);background:rgba(13,27,42,.75);padding:0 3px;border-radius:3px}
.chart-max{top:0}.chart-min{top:32px}
.chart-x{display:flex;justify-content:space-between;font-size:var(--tk-fs-meta);color:var(--tk-dim)}
.sun-times .tk-value{font-size:16px}
/* Vorhersage: Tageskacheln reihen sich ins Raster ein (display:contents) */
.fc-wrap{display:contents}
.fc-day{justify-content:flex-start;gap:4px}
.fc-halves{display:flex;gap:6px}
.fc-half{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:1px;text-align:center}
.fc-half img{width:30px;height:30px}
.fc-noicon{font-size:18px;color:var(--tk-dim)}
.fc-temp{font-size:var(--tk-fs-text);font-weight:700}
.fc-desc{line-height:1.15}
CSS;
        $js = <<<'JS'
var lastHtml = { warn_table: state.warnTable, radar: state.radar, forecast: state.forecast };
function frame(id, html) {
  if (lastHtml[id] === html) return;
  lastHtml[id] = html;
  var f = tk.el(id + '_frame');
  if (f) f.srcdoc = f.srcdoc.replace(/<\/style>[\s\S]*$/, '</style>') + html;
}
function status(id, s) {
  tk.setText(id + '_val', s.text); tk.setText(id + '_icon', s.icon);
  var el = tk.el(id + '_status'), card = tk.el(id + '_card');
  if (el) ['ok', 'warn', 'alarm', 'accent'].forEach(function (c) { el.classList.toggle('tk-' + c, c === s.state); });
  if (card) { card.classList.toggle('tk-is-alarm', s.state === 'alarm'); card.classList.toggle('tk-is-warn', s.state === 'warn'); }
}
tk.on('__all__', function (v) {
  state = v;
  tk.setText('temp_val', v.temp);
  var poly = tk.el('temp_poly'); if (poly && v.chart) poly.setAttribute('points', v.chart);
  tk.setText('chart_max', v.chartMax); tk.setText('chart_min', v.chartMin);
  tk.setText('hum_val', v.humidity); tk.setText('wind_val', v.wind); tk.setText('wind_sub', v.windDir);
  tk.setText('illum_val', v.illum); tk.setText('sunshine_val', v.sunshine); tk.setText('rain_today_val', v.rainToday); tk.setText('sun_val', v.sun);
  status('rain_now', v.rainNow); status('sun_now', v.sunNow); status('pollen', v.pollen); status('warn', v.warn);
  var w = tk.el('warn_table_wrap'); if (w) w.hidden = v.warnTable === '';
  frame('warn_table', v.warnTable); frame('radar', v.radar);
  if (lastHtml.forecast !== v.forecast) { lastHtml.forecast = v.forecast; tk.setHtml('forecast', v.forecast); }
});
JS;
        return $this->tkPage('Wetter', '🌦️', $v['updated'], $body, $css, $js, json_encode($v));
    }
}
