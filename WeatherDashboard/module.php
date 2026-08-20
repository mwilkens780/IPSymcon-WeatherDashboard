<?php

declare(strict_types=1);

class WeatherDashboard extends IPSModule
{
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
            $data = $this->collectData();
            $this->pushValue('__all__', $data);
            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('WeatherDashboard Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function pushValue(string $key, $value): void
    {
        $this->UpdateVisualizationValue(json_encode(['key' => $key, 'value' => $value]));
    }

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

        $forecast = [];
        for ($i = 0; $i < $segments; $i++) {
            $icon = $this->readForeign($forecastInstance, "DP{$i}Icon");
            $temp = $this->readForeign($forecastInstance, "DP{$i}Temperature");
            $forecast[] = [
                'icon'  => $icon !== null ? (int) $icon : null,
                'temp'  => $temp !== null ? (float) $temp : null,
                'label' => $this->iconLabel($icon !== null ? (int) $icon : null),
                'time'  => date('D H:i', time() + $i * 12 * 3600),
            ];
        }

        $warnLevel = $this->readForeign($warningInstance, 'Level');
        $warnText  = $this->readForeign($warningInstance, 'Text');
        $warnTable = $this->readForeign($warningInstance, 'Table');
        $radarHtml = $this->readForeign($warningInstance, 'MovRadar');

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
            'warnLevel'     => $warnLevel !== null ? (int) $warnLevel : null,
            'warnText'      => $warnText,
            'warnTable'     => $warnTable,
            'radarHtml'     => $radarHtml,
            'forecast'      => $forecast,
            'updated'       => date('d.m. H:i'),
        ];
    }

    private function levelClass(?int $level): string
    {
        return match ($level) {
            0       => 'badge-off',
            1       => 'badge-yellow',
            2       => 'badge-amber',
            3       => 'badge-red',
            4       => 'badge-violet',
            default => 'badge-off',
        };
    }

    private function buildDashboardHTML(): string
    {
        $d = $this->collectData();

        $tempStr    = $d['temp'] !== null ? number_format((float) $d['temp'], 1, ',', '') . ' °C' : '–';
        $humStr     = $d['humidity'] !== null ? round((float) $d['humidity']) . '%' : '–';
        $windStr    = $d['windSpeed'] !== null ? number_format((float) $d['windSpeed'], 1, ',', '') . ' km/h' : '–';
        $windDirStr = $this->compassText($d['windDir'] !== null ? (float) $d['windDir'] : null);
        $illumStr   = $d['illumination'] !== null ? round((float) $d['illumination']) . ' lx' : '–';
        $rainNow    = (bool) $d['rainNow'];
        $sunNow     = (bool) $d['sunNow'];

        $sunshineMin  = $d['sunshineToday'] !== null ? (int) round((float) $d['sunshineToday']) : null;
        $sunshineStr  = $sunshineMin !== null ? sprintf('%dh %02dmin', intdiv($sunshineMin, 60), $sunshineMin % 60) : '–';
        $rainTodayStr = $d['rainToday'] !== null ? number_format((float) $d['rainToday'], 1, ',', '') . ' mm' : '–';

        $warnLevel = $d['warnLevel'];
        $warnCls   = $this->levelClass($warnLevel);
        $warnText  = $d['warnText'] !== null && $d['warnText'] !== '' ? (string) $d['warnText'] : 'Keine Warnung';
        $warnTextEsc = htmlspecialchars($warnText, ENT_QUOTES);
        $hasWarning  = $warnLevel !== null && $warnLevel > 0;

        $warnTableSrcDoc = $hasWarning && $d['warnTable'] !== null
            ? htmlspecialchars((string) $d['warnTable'], ENT_QUOTES)
            : '';
        $warnTableBlock = $warnTableSrcDoc !== ''
            ? "<div class=\"warn-table-wrap\"><iframe id=\"warn_table\" srcdoc=\"{$warnTableSrcDoc}\"></iframe></div>"
            : '';

        $radarSrcDoc = $d['radarHtml'] !== null
            ? htmlspecialchars((string) $d['radarHtml'], ENT_QUOTES)
            : '';
        $radarBlock = $radarSrcDoc !== ''
            ? "<div class=\"radar-wrap\"><iframe id=\"radar\" srcdoc=\"{$radarSrcDoc}\"></iframe></div>"
            : '';

        $rainCls   = $rainNow ? 'badge-on' : 'badge-off';
        $rainLabel = $rainNow ? 'Regnet' : 'Trocken';
        $sunCls    = $sunNow ? 'badge-green' : 'badge-off';
        $sunLabel  = $sunNow ? 'Sonne aktiv' : 'Keine Sonne';

        // Forecast strip
        $forecastHtml = '';
        foreach ($d['forecast'] as $seg) {
            $iconUri  = $this->iconDataUri($seg['icon']);
            $imgTag   = $iconUri !== '' ? "<img src='{$iconUri}' alt=''/>" : "<span class='fc-noicon'>–</span>";
            $tempSeg  = $seg['temp'] !== null ? round($seg['temp']) . '°' : '–';
            $labelEsc = htmlspecialchars($seg['label'], ENT_QUOTES);
            $timeEsc  = htmlspecialchars($seg['time'], ENT_QUOTES);
            $forecastHtml .= "<div class='fc-item'>"
                . "<div class='fc-time'>{$timeEsc}</div>"
                . "<div class='fc-icon'>{$imgTag}</div>"
                . "<div class='fc-temp'>{$tempSeg}</div>"
                . "<div class='fc-label'>{$labelEsc}</div>"
                . '</div>';
        }

        $updatedEsc = htmlspecialchars($d['updated'], ENT_QUOTES);

        $initJson = json_encode($d);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
html{height:100%}
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-y:auto;overflow-x:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;background:#0d1b2a;color:#d0e8ff;display:flex;flex-direction:column;padding:10px;gap:8px}
.header{display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:14px;font-weight:600;border-bottom:1px solid #1e3a5f;padding-bottom:6px;flex:none}
.updated{font-size:10px;color:#3a5a7a;font-weight:400}
.badge{padding:3px 8px;border-radius:12px;font-size:12px;border:1px solid transparent;white-space:nowrap}
.badge-on{background:#1e4a6e;border-color:#3a8abf;color:#7ec8f0}
.badge-off{background:#1a2535;border-color:#2a3a50;color:#4a6a8a}
.badge-warn{background:#4a2010;border-color:#8a4020;color:#f08060}
.badge-green{background:#124a1e;border-color:#2f8a44;color:#7ee89a}
.badge-amber{background:#4a3510;border-color:#8a6a20;color:#f0c060}
.badge-yellow{background:#4a4310;border-color:#8a7d20;color:#f0e060}
.badge-red{background:#4a1010;border-color:#8a2020;color:#f06060}
.badge-violet{background:#3a1050;border-color:#7020a0;color:#d090f0}
.current-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;flex:none}
.cur-tile{display:flex;flex-direction:column;gap:1px;background:#131f33;border-radius:8px;padding:6px 8px}
.cur-label{font-size:10px;color:#4a6a8a;text-transform:uppercase;letter-spacing:.03em}
.cur-value{font-size:16px;font-weight:700;color:#d0e8ff}
.status-row{display:flex;gap:6px;flex-wrap:wrap;flex:none}
.warn-row{display:flex;align-items:center;gap:8px;flex:none}
.warn-table-wrap{flex:none;height:140px}
.warn-table-wrap iframe{width:100%;height:100%;border:none;border-radius:8px;background:#fff}
.radar-wrap{flex:1;min-height:80px;border-radius:8px;overflow:hidden}
.radar-wrap iframe{width:100%;height:100%;border:none;background:#0a1526}
.forecast-strip{display:flex;gap:8px;overflow-x:auto;flex:none;padding-bottom:2px}
.fc-item{flex:0 0 auto;display:flex;flex-direction:column;align-items:center;gap:2px;background:#131f33;border-radius:8px;padding:6px 8px;min-width:64px}
.fc-time{font-size:9px;color:#4a6a8a}
.fc-icon img{width:32px;height:32px}
.fc-noicon{font-size:20px;color:#4a6a8a}
.fc-temp{font-size:13px;font-weight:700}
.fc-label{font-size:9px;color:#8aa8c8;text-align:center;line-height:1.2}
</style>
</head>
<body>
<div class="header">
  <span>🌦 Wetter <span id="updated" class="updated">Stand {$updatedEsc}</span></span>
  <span id="warn_badge" class="badge {$warnCls}">⚠ {$warnTextEsc}</span>
</div>
<div class="current-grid">
  <div class="cur-tile"><span class="cur-label">Temperatur</span><span id="cur_temp" class="cur-value">{$tempStr}</span></div>
  <div class="cur-tile"><span class="cur-label">Luftfeuchte</span><span id="cur_hum" class="cur-value">{$humStr}</span></div>
  <div class="cur-tile"><span class="cur-label">Helligkeit</span><span id="cur_illum" class="cur-value">{$illumStr}</span></div>
  <div class="cur-tile"><span class="cur-label">Wind</span><span id="cur_wind" class="cur-value">{$windStr}</span></div>
  <div class="cur-tile"><span class="cur-label">Windrichtung</span><span id="cur_winddir" class="cur-value">{$windDirStr}</span></div>
  <div class="cur-tile"><span class="cur-label">Sonne heute</span><span id="cur_sunshine" class="cur-value">{$sunshineStr}</span></div>
</div>
<div class="status-row">
  <span id="badge_rain" class="badge {$rainCls}">🌧 {$rainLabel}</span>
  <span id="badge_sun" class="badge {$sunCls}">☀ {$sunLabel}</span>
  <span id="badge_raintoday" class="badge badge-off">💧 heute {$rainTodayStr}</span>
</div>
{$warnTableBlock}
{$radarBlock}
<div id="forecast_strip" class="forecast-strip">{$forecastHtml}</div>
<script>
// WebFront injects its own body{margin-top:...;margin-bottom:...} (reserved
// space for the tile's title/expand-icon overlay). Measure it and size body
// to exactly fill what's left instead of guessing a fixed pixel value.
(function() {
  var cs = getComputedStyle(document.body);
  var vExtra = (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
  document.body.style.height = 'calc(100% - ' + vExtra + 'px)';
})();

var state = {$initJson};

function setText(id, text) {
  var el = document.getElementById(id);
  if (el) el.textContent = text;
}

window.handleMessage = function(raw) {
  var msg = JSON.parse(raw);
  if (msg.key !== '__all__') return;
  var d = msg.value;
  state = d;

  setText('updated', 'Stand ' + d.updated);
  setText('cur_temp', d.temp == null ? '–' : d.temp.toFixed(1).replace('.', ',') + ' °C');
  setText('cur_hum', d.humidity == null ? '–' : Math.round(d.humidity) + '%');
  setText('cur_illum', d.illumination == null ? '–' : Math.round(d.illumination) + ' lx');
  setText('cur_wind', d.windSpeed == null ? '–' : d.windSpeed.toFixed(1).replace('.', ',') + ' km/h');
  setText('cur_sunshine', d.sunshineToday == null ? '–' : (Math.floor(d.sunshineToday/60) + 'h ' + String(Math.round(d.sunshineToday%60)).padStart(2,'0') + 'min'));

  var rainBadge = document.getElementById('badge_rain');
  if (rainBadge) {
    rainBadge.className = 'badge ' + (d.rainNow ? 'badge-on' : 'badge-off');
    rainBadge.textContent = '🌧 ' + (d.rainNow ? 'Regnet' : 'Trocken');
  }
  var sunBadge = document.getElementById('badge_sun');
  if (sunBadge) {
    sunBadge.className = 'badge ' + (d.sunNow ? 'badge-green' : 'badge-off');
    sunBadge.textContent = '☀ ' + (d.sunNow ? 'Sonne aktiv' : 'Keine Sonne');
  }
  setText('badge_raintoday', '💧 heute ' + (d.rainToday == null ? '–' : d.rainToday.toFixed(1).replace('.', ',') + ' mm'));

  var warnBadge = document.getElementById('warn_badge');
  if (warnBadge) {
    var cls = {0:'badge-off',1:'badge-yellow',2:'badge-amber',3:'badge-red',4:'badge-violet'}[d.warnLevel] || 'badge-off';
    warnBadge.className = 'badge ' + cls;
    warnBadge.textContent = '⚠ ' + (d.warnText || 'Keine Warnung');
  }
};
</script>
</body>
</html>
HTML;
    }
}
