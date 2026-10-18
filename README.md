# SymconSML

IP-Symcon-Modul für drei bestehende SML-Push-Zähler (PV, EVU, Wärmepumpe), zeitliche Messwertausrichtung, sparsame Archivwerte und Empfangsdiagnose.

## Stand
**0.1 – experimentell**, keine Produktivfreigabe. Die bestehenden SML-Empfänger, Variablen, Archivdaten und KNX-Verknüpfungen werden nicht verändert. KNX-Ausgabe ist in dieser Version nicht integriert.

## Architektur
1. Bestehende RegisterVariable-Instanzen empfangen serielle SML-Telegramme; Bridge-Skripte rufen `SMLH_Ingest` mit base64-kodierten Rohbytes auf.
2. Je Kanal werden Messwerte mit Empfangszeitstempel (`microtime(true)`) für bis zu 90 Sekunden gepuffert.
3. Die Hausleistung wird mit einem gemeinsamen, leicht verzögerten Zielzeitpunkt aus PV-Erzeugung und *vorzeichenbehafteter* Netzleistung interpoliert: `P_Haus = P_PV + P_Netz`.
4. Hausenergie wird aus interpolierten Zählerständen ermittelt: `E_Haus = E_PV + E_Bezug - E_Einspeisung`.
5. Archiveingang: Leistung 10 W Delta / 5 s Heartbeat; Zählerstände 10 Wh Delta / 300 s Heartbeat; konfigurierbar.
6. Kein dauerhaftes Debug-Logging von Rohtelegrammen; Ausfallstatus und Fehlerzähler.

## Installation
Über IP-Symcon Modulverwaltung GitHub URL hinzufügen, Modulinstanz erstellen. Die drei Bridge-Skripte enthalten noch `$moduleId = 0` und sind erst nach Änderung der Instanz-ID aktiv. Vorhandene Empfangsskripte vor Umstellung sichern. `EnableOutputs` bleibt zunächst deaktiviert.

## Einschränkungen
Die Empfangszeit ist nicht zwingend die tatsächliche Messzeit. Ohne vom Zähler gelieferte Zeitstempel handelt es sich um eine Schätzung. Der OBIS-Decoder ist hausspezifisch und muss mit realen SML-Telegrammen validiert werden. Es gibt noch keinen automatischen Wechsel von historischen Variablen und keine KNX-Schreibfunktionen. Keine Rekonstruktion fehlender Messwerte durch Extrapolation.

## Test
`php tests/alignment.php` sowie `php -l SymconSML/module.php`.

Siehe Modulkonfiguration für Deadbands, Interpolationsfenster und Freigabe.
