# Weather Dashboard – IP-Symcon Modul

Grafische HTML-SDK-Kachel für Wetterdaten, im selben dunklen Dashboard-Stil wie die SunRiser8- und BMW-CarData-Module. Liest ausschließlich bereits vorhandene IPS-Variablen und -Instanzen aus (Homematic-Wetterstation, "Weather Warning"-Modul, "WundergroundPWSSync"-Modul) — keine eigene API-Anbindung, kein OAuth.

## Installation

Modulverwaltung → + → URL eintragen:
```
https://github.com/mwilkens780/IPSymcon-WeatherDashboard
```

## Konfiguration

Die Instanz-Einstellungen sind mit den Objekt-IDs dieser konkreten Installation vorbelegt (Kategorie "Wetter", 56501). Bei Bedarf über die Variablen-/Instanz-Auswahl anpassen:

- **Aktuelle Werte**: Temperatur, Luftfeuchtigkeit, Windgeschwindigkeit/-richtung, Helligkeit, Regen/Sonne jetzt, Sonnenschein/Regen heute
- **Unwetterwarnung**: Instanz des "Weather Warning"-Moduls (liefert Warnstufe, Warntext, Warnmeldungs-Tabelle, Regenradar-Film)
- **Wettervorhersage**: Instanz des "WundergroundPWSSync"-Moduls (liefert Icon-Code + Temperatur je 12h-Segment)

## Kachel einrichten

Die Kachel in einer Tile-Visualization platzieren, mindestens 4 Spalten × 4 Zeilen für vollständige Darstellung.

## Piktogramme

Nutzt die vom WundergroundPWSSync-Modul mitgelieferten PNG-Icons (Standard-"Weather Channel"-Icon-Set, Codes 0–47), eingebettet als Base64-Data-URI — kein externer Request nötig. Der Beschreibungstext je Segment wird aus dem Icon-Code abgeleitet (das Original-Textfeld der Vorhersage-API liefert bei diesem Modul keine Daten).
