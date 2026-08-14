<?php

/**
 * GKS-Block - IP-Symcon Modul
 *
 * Bildet einen GKS-Block (3 parallel/seriell geschaltete Magnetventile
 * V1 = Nebenventil, V2 = Hauptventil, V3 = Bypassventil) inklusive
 * automatischer Freigabe-Prüfsequenz (Netzfüllung -> Dichtheitsprüfung ->
 * Netzfüllung -> Dichtheitsprüfung -> Freigabe) und manuellem Handbetrieb
 * je Ventil nach. Eine Instanz entspricht genau einem GKS-Block (= einem
 * Gas), analog zu den GKS-Block-Kacheln im Burghausen-Dashboard.
 *
 * Ablauf der Prüfsequenz (identisch zur Dashboard-Simulation):
 *   fill1  -> Netzfüllung auf Fill1Target bar (V1 auf, V2/V3 zu)
 *   test1  -> Prüfzeit 1 (Dichtheit), V1 zu
 *   fill2  -> Netzfüllung auf Fill2Target bar, V1 auf
 *   test2  -> Prüfzeit 2 (Dichtheit), V1 zu
 *   released -> Freigabe: V1 zu, V2 + V3 auf
 *
 * "Sperre GKS" schließt sofort alle drei Ventile und setzt alle
 * Betriebsarten auf Automatik zurück (Sicherheitsfunktion).
 */

class GKSBlock extends IPSModule
{
    // ---- Interne Konstanten ----
    private const PROFILE_VALVE   = 'GKSB.ValveState';   // Bool: zu/auf
    private const PROFILE_MODE    = 'GKSB.ValveMode';    // Integer: Automatik/Hand
    private const PROFILE_PRESSURE = 'GKSB.Pressure';    // Float: bar
    private const PROFILE_STATUS  = 'GKSB.BlockStatus';  // Integer: OK/Störung/Alarm

    private const MODE_AUTO = 0;
    private const MODE_HAND = 1;

    private const STATUS_OK       = 0;
    private const STATUS_WARNING  = 1;
    private const STATUS_ALARM    = 2;

    // Bereitschaftszustand außerhalb einer laufenden Sequenz:
    // Nebenventil zu, Haupt-/Bypassventil auf.
    private const IDLE_VALVE_STATE = ['V1' => false, 'V2' => true, 'V3' => true];

    public function Create()
    {
        parent::Create();

        // ---- Gaszuordnung ----
        $this->RegisterPropertyString('GasSymbol', '');
        $this->RegisterPropertyString('GasName', '');
        $this->RegisterPropertyString('GasNameEn', '');

        // ---- Netzdruck ----
        $this->RegisterPropertyInteger('NetworkPressureVariableID', 0);
        $this->RegisterPropertyFloat('PressureWarnBelow', 8.0);   // 0 = Überwachung deaktiviert
        $this->RegisterPropertyFloat('PressureAlarmBelow', 6.0);  // 0 = Überwachung deaktiviert

        // ---- Ausgänge zu realer Hardware (optional) ----
        $this->RegisterPropertyInteger('OutputVariableID_V1', 0);
        $this->RegisterPropertyInteger('OutputVariableID_V2', 0);
        $this->RegisterPropertyInteger('OutputVariableID_V3', 0);

        // ---- Prüfsequenz-Parameter ----
        $this->RegisterPropertyFloat('Fill1Target', 3.0);
        $this->RegisterPropertyInteger('Test1Duration', 10);
        $this->RegisterPropertyFloat('Fill2Target', 8.0);
        $this->RegisterPropertyInteger('Test2Duration', 10);
        $this->RegisterPropertyFloat('FillRate', 0.6); // bar/s, nur Simulation ohne externe Druckvariable

        // ---- Laufzeit-Timer (Intervall wird dynamisch gesetzt/gestoppt) ----
        $this->RegisterTimer('SequenceTimer', 0, 'GKSB_Tick($_IPS[\'TARGET\']);');

        // Referenz-Zeitpunkte/Zwischenwerte, die keine eigene Statusvariable
        // benötigen, werden im Instanz-Puffer gehalten (nicht persistent
        // über einen Symcon-Neustart hinweg - das ist für eine laufende
        // Prüfsequenz auch nicht nötig, sie würde ohnehin neu gestartet).
        $this->SetBuffer('PhaseEndTime', '0');
        $this->SetBuffer('SequenceStartTime', '0');
        $this->SetBuffer('SimulatedPressure', '0');
        $this->SetBuffer('FillTarget', '0');
        $this->SetBuffer('RegisteredPressureVarID', '0');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();

        // ---- Statusvariablen ----
        $this->RegisterVariableBoolean('V1', $this->T('Nebenventil (V1)', 'Secondary valve (V1)'), self::PROFILE_VALVE, 10);
        $this->RegisterVariableBoolean('V2', $this->T('Hauptventil (V2)', 'Main valve (V2)'), self::PROFILE_VALVE, 20);
        $this->RegisterVariableBoolean('V3', $this->T('Bypassventil (V3)', 'Bypass valve (V3)'), self::PROFILE_VALVE, 30);
        $this->EnableAction('V1');
        $this->EnableAction('V2');
        $this->EnableAction('V3');

        $this->RegisterVariableInteger('V1Mode', $this->T('Betriebsart V1', 'Mode V1'), self::PROFILE_MODE, 11);
        $this->RegisterVariableInteger('V2Mode', $this->T('Betriebsart V2', 'Mode V2'), self::PROFILE_MODE, 21);
        $this->RegisterVariableInteger('V3Mode', $this->T('Betriebsart V3', 'Mode V3'), self::PROFILE_MODE, 31);
        $this->EnableAction('V1Mode');
        $this->EnableAction('V2Mode');
        $this->EnableAction('V3Mode');

        $this->RegisterVariableFloat('NetworkPressure', $this->T('Netzdruck', 'Network pressure'), self::PROFILE_PRESSURE, 40);
        $this->RegisterVariableString('Phase', $this->T('Phase', 'Phase'), '', 50);
        $this->RegisterVariableInteger('RemainingSeconds', $this->T('Restlaufzeit Prüfvorgang', 'Remaining test time'), '', 51);
        $this->RegisterVariableInteger('TotalRuntime', $this->T('Gesamtlaufzeit Prüfprozess', 'Total test process runtime'), '', 52);
        $this->RegisterVariableString('ReleaseHint', $this->T('Hinweis Freigabe', 'Release hint'), '', 53);
        $this->RegisterVariableInteger('BlockStatus', $this->T('Sammelstatus', 'Collective status'), self::PROFILE_STATUS, 5);

        $this->RegisterVariableBoolean('Freigeben', $this->T('Freigabe GKS', 'Release GKS'), '~Button', 60);
        $this->EnableAction('Freigeben');
        $this->RegisterVariableBoolean('Sperren', $this->T('Sperre GKS', 'Lock GKS'), '~Button', 61);
        $this->EnableAction('Sperren');

        // Initialzustand nur beim allerersten Anlegen setzen (nicht bei
        // jedem "Übernehmen"), damit ein laufender Betrieb nicht durch das
        // Speichern der Konfiguration zurückgesetzt wird.
        if ($this->GetValue('Phase') === '') {
            $this->WriteAllValves(self::IDLE_VALVE_STATE);
            $this->SetValue('V1Mode', self::MODE_AUTO);
            $this->SetValue('V2Mode', self::MODE_AUTO);
            $this->SetValue('V3Mode', self::MODE_AUTO);
            $this->SetValue('Phase', 'idle');
            $this->SetValue('RemainingSeconds', 0);
            $this->SetValue('TotalRuntime', 0);
        }

        // ---- Nachricht bei Änderung der verknüpften Netzdruck-Variable ----
        // UnregisterMessage benötigt die konkrete, zuvor registrierte
        // Sender-ID - daher merken wir sie uns im Puffer, statt versuchsweise
        // eine Dummy-ID abzumelden.
        $previousPressureVarID = (int) $this->GetBuffer('RegisteredPressureVarID');
        if ($previousPressureVarID > 0) {
            $this->UnregisterMessage($previousPressureVarID, VM_UPDATE);
        }
        $pressureVarID = $this->ReadPropertyInteger('NetworkPressureVariableID');
        if ($pressureVarID > 0 && @IPS_VariableExists($pressureVarID)) {
            $this->RegisterMessage($pressureVarID, VM_UPDATE);
            $this->SetBuffer('RegisteredPressureVarID', (string) $pressureVarID);
        } else {
            $this->SetBuffer('RegisteredPressureVarID', '0');
        }

        // Status der Instanz (Konfigurationsprüfung)
        if ($this->ReadPropertyString('GasSymbol') === '') {
            $this->SetStatus(101);
            return;
        }
        $this->SetStatus(102);

        $this->RefreshNetworkPressure();
        $this->RecomputeBlockStatus();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE && $SenderID === $this->ReadPropertyInteger('NetworkPressureVariableID')) {
            $this->RefreshNetworkPressure();
            $this->RecomputeBlockStatus();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'V1':
            case 'V2':
            case 'V3':
                $this->SwitchValve($Ident, (bool) $Value);
                break;

            case 'V1Mode':
            case 'V2Mode':
            case 'V3Mode':
                $valve = substr($Ident, 0, 2); // 'V1'/'V2'/'V3'
                $this->SetValveMode($valve, ((int) $Value) === self::MODE_HAND);
                break;

            case 'Freigeben':
                $this->Freigeben();
                $this->SetValue('Freigeben', false); // Taster fällt optisch zurück
                break;

            case 'Sperren':
                $this->Sperren();
                $this->SetValue('Sperren', false);
                break;

            default:
                throw new Exception('Invalid ident: ' . $Ident);
        }
    }

    // ================================================================
    // ---- Öffentliche Modul-Funktionen (auch per GKSB_Xxx($id) aus
    //      Skripten/Ablaufsteuerungen aufrufbar) ----
    // ================================================================

    /**
     * "Freigabe GKS": startet die komplette Prüfsequenz - sofern gerade
     * keine Sequenz bereits läuft. Ist mindestens ein Ventil im
     * Handbetrieb, wird die Sequenz nicht gestartet, stattdessen wird der
     * Hinweistext (ReleaseHint) gesetzt (identisch zum Dashboard-Verhalten).
     */
    public function Freigeben()
    {
        $phase = $this->GetValue('Phase');
        if (in_array($phase, ['fill1', 'test1', 'fill2', 'test2'], true)) {
            return; // Sequenz läuft bereits
        }

        $handValves = [];
        foreach (['V1', 'V2', 'V3'] as $valve) {
            if ($this->GetValue($valve . 'Mode') === self::MODE_HAND) {
                $handValves[] = $valve;
            }
        }
        if (count($handValves) > 0) {
            $hintDe = 'Freigabe nicht möglich – Ventil(e) ' . implode(', ', $handValves)
                . ' sind im Handbetrieb. Bitte zuerst alle Ventile auf Automatik zurückschalten.';
            $this->SetValue('ReleaseHint', $hintDe);
            $this->LogMessage('GKS-Freigabe blockiert (Handbetrieb): ' . implode(', ', $handValves), KL_NOTIFY);
            return;
        }
        $this->SetValue('ReleaseHint', '');

        $this->SetBuffer('SequenceStartTime', (string) time());
        $this->SetValue('TotalRuntime', 0);

        $this->SetValue('Phase', 'fill1');
        $this->SetValveInternal('V1', true);
        $this->SetValveInternal('V2', false);
        $this->SetValveInternal('V3', false);

        // Simulierten Druck nur hier, zu Beginn der GESAMTEN Sequenz, auf
        // 0 zurücksetzen (nicht bei jeder einzelnen Füllphase - Füllphase 2
        // muss nahtlos vom Ende der Füllphase 1 aus weiterlaufen).
        if ($this->ReadPropertyInteger('NetworkPressureVariableID') === 0) {
            $this->SetBuffer('SimulatedPressure', '0');
            $this->SetValue('NetworkPressure', 0.0);
        }
        $this->StartFillPhase((float) $this->ReadPropertyFloat('Fill1Target'));

        $this->StartSequenceTimer();
        $this->RecomputeBlockStatus();
    }

    /**
     * "Sperre GKS": bricht eine laufende Sequenz jederzeit ab und schließt
     * ALLE Ventile - als Sicherheitsfunktion unabhängig vom Handbetrieb.
     * Setzt zusätzlich alle Betriebsarten auf Automatik zurück.
     */
    public function Sperren()
    {
        $this->StopSequenceTimer();

        $this->SetValue('V1Mode', self::MODE_AUTO);
        $this->SetValue('V2Mode', self::MODE_AUTO);
        $this->SetValue('V3Mode', self::MODE_AUTO);
        $this->WriteAllValves(['V1' => false, 'V2' => false, 'V3' => false]);

        $this->SetValue('Phase', 'locked');
        $this->SetValue('RemainingSeconds', 0);
        $this->SetValue('ReleaseHint', '');

        $this->RecomputeBlockStatus();
    }

    /**
     * Betriebsart eines Ventils umschalten (Automatik <-> Hand). Beim
     * Zurückschalten auf Automatik wird das Ventil - sofern gerade keine
     * Sequenz läuft - in den definierten Bereitschaftszustand
     * zurückgesetzt (V1 zu, V2/V3 auf).
     */
    public function SetValveMode(string $Valve, bool $Hand)
    {
        $this->ValidateValveIdent($Valve);
        $mode = $Hand ? self::MODE_HAND : self::MODE_AUTO;
        $this->SetValue($Valve . 'Mode', $mode);

        if (!$Hand) {
            $phase = $this->GetValue('Phase');
            $sequenceRunning = in_array($phase, ['fill1', 'test1', 'fill2', 'test2'], true);
            if (!$sequenceRunning) {
                $this->SetValveInternal($Valve, self::IDLE_VALVE_STATE[$Valve]);
            }
        }

        $this->RecomputeBlockStatus();
    }

    /**
     * Manuelles Schalten eines Ventils - nur wirksam, wenn sich das Ventil
     * gerade im Handbetrieb befindet (sonst wird der Aufruf ignoriert, die
     * WebFront-Kachel springt beim nächsten Status-Refresh automatisch auf
     * den tatsächlichen Wert zurück).
     */
    public function SwitchValve(string $Valve, bool $Open)
    {
        $this->ValidateValveIdent($Valve);
        if ($this->GetValue($Valve . 'Mode') !== self::MODE_HAND) {
            $this->LogMessage("$Valve kann nur im Handbetrieb manuell geschaltet werden.", KL_NOTIFY);
            $this->ReloadVariable($Valve); // WebFront-Wert auf tatsächlichen Zustand zurücksetzen
            return;
        }
        $this->SetValveInternal($Valve, $Open);
        $this->RecomputeBlockStatus();
    }

    /**
     * Wird vom Symcon-Timer periodisch aufgerufen, solange eine Prüfsequenz
     * aktiv ist (fill1/test1/fill2/test2). Führt die Phasenübergänge
     * anhand des aktuellen Netzdrucks bzw. der verstrichenen Zeit durch.
     */
    public function Tick()
    {
        $phase = $this->GetValue('Phase');

        switch ($phase) {
            case 'fill1':
                $this->RefreshNetworkPressure();
                if ($this->GetValue('NetworkPressure') >= (float) $this->ReadPropertyFloat('Fill1Target')) {
                    $this->SetValveInternal('V1', false);
                    $this->SetValue('Phase', 'test1');
                    $this->StartCountdown((int) $this->ReadPropertyInteger('Test1Duration'));
                }
                break;

            case 'test1':
                if ($this->TickCountdown()) {
                    $this->SetValveInternal('V1', true);
                    $this->SetValue('Phase', 'fill2');
                    $this->StartFillPhase((float) $this->ReadPropertyFloat('Fill2Target'));
                }
                break;

            case 'fill2':
                $this->RefreshNetworkPressure();
                if ($this->GetValue('NetworkPressure') >= (float) $this->ReadPropertyFloat('Fill2Target')) {
                    $this->SetValveInternal('V1', false);
                    $this->SetValue('Phase', 'test2');
                    $this->StartCountdown((int) $this->ReadPropertyInteger('Test2Duration'));
                }
                break;

            case 'test2':
                if ($this->TickCountdown()) {
                    // Abschließende Freigabe: V1 zu, V2 + V3 auf.
                    $this->SetValveInternal('V1', false);
                    $this->SetValveInternal('V2', true);
                    $this->SetValveInternal('V3', true);
                    $this->SetValue('Phase', 'released');
                    $this->SetValue('RemainingSeconds', 0);
                    $start = (int) $this->GetBuffer('SequenceStartTime');
                    if ($start > 0) {
                        $this->SetValue('TotalRuntime', time() - $start);
                    }
                    $this->StopSequenceTimer();
                }
                break;

            default:
                // Sollte nicht eintreten (Timer läuft nur während aktiver Phasen) -
                // sicherheitshalber Timer stoppen.
                $this->StopSequenceTimer();
        }

        $this->RecomputeBlockStatus();
    }

    // ================================================================
    // ---- Private Hilfsfunktionen ----
    // ================================================================

    private function ValidateValveIdent(string $Valve): void
    {
        if (!in_array($Valve, ['V1', 'V2', 'V3'], true)) {
            throw new Exception('Unbekanntes Ventil: ' . $Valve);
        }
    }

    /** Übersetzt de/en anhand der Symcon-Sprache des Servers (Formular-/Variablennamen). */
    private function T(string $de, string $en): string
    {
        // IPS_GetSystemLanguage() liefert z. B. "de_DE" / "en_US".
        $lang = @IPS_GetSystemLanguage();
        return (is_string($lang) && strpos($lang, 'de') === 0) ? $de : $en;
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists(self::PROFILE_VALVE)) {
            IPS_CreateVariableProfile(self::PROFILE_VALVE, VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation(self::PROFILE_VALVE, false, 'Zu', '', 0xE53E2B);
            IPS_SetVariableProfileAssociation(self::PROFILE_VALVE, true, 'Auf', '', 0x2FAE60);
        }

        if (!IPS_VariableProfileExists(self::PROFILE_MODE)) {
            IPS_CreateVariableProfile(self::PROFILE_MODE, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation(self::PROFILE_MODE, self::MODE_AUTO, 'Automatik', '', 0x8A98A0);
            IPS_SetVariableProfileAssociation(self::PROFILE_MODE, self::MODE_HAND, 'Hand', '', 0xF2A900);
        }

        if (!IPS_VariableProfileExists(self::PROFILE_PRESSURE)) {
            IPS_CreateVariableProfile(self::PROFILE_PRESSURE, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText(self::PROFILE_PRESSURE, '', ' bar');
            IPS_SetVariableProfileDigits(self::PROFILE_PRESSURE, 1);
        }

        if (!IPS_VariableProfileExists(self::PROFILE_STATUS)) {
            IPS_CreateVariableProfile(self::PROFILE_STATUS, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation(self::PROFILE_STATUS, self::STATUS_OK, 'OK', '', 0x2FAE60);
            IPS_SetVariableProfileAssociation(self::PROFILE_STATUS, self::STATUS_WARNING, 'Störung', '', 0xF2A900);
            IPS_SetVariableProfileAssociation(self::PROFILE_STATUS, self::STATUS_ALARM, 'Alarm', '', 0xE53E2B);
        }
    }

    /** Schreibt alle drei Ventile in einem Rutsch (z. B. Initialzustand, Sperre). */
    private function WriteAllValves(array $states): void
    {
        foreach ($states as $valve => $open) {
            $this->SetValveInternal($valve, $open);
        }
    }

    /**
     * Setzt den Ventilzustand intern (Automatik-Sequenz, Sperre, oder nach
     * Freigabe im Handbetrieb bereits geprüfter manueller Schaltvorgang) -
     * aktualisiert die Statusvariable und spiegelt den Zustand optional auf
     * eine verknüpfte reale Ausgangsvariable (Hardware).
     */
    private function SetValveInternal(string $Valve, bool $Open): void
    {
        $this->ValidateValveIdent($Valve);
        $this->SetValue($Valve, $Open);

        $outputID = $this->ReadPropertyInteger('OutputVariableID_' . $Valve);
        if ($outputID > 0 && @IPS_VariableExists($outputID)) {
            @RequestAction($outputID, $Open);
        }
    }

    /** Setzt eine WebFront-Kachel (z. B. nach einer ungültigen Schaltaktion) auf den tatsächlichen Wert zurück. */
    private function ReloadVariable(string $Ident): void
    {
        $id = $this->GetIDForIdent($Ident);
        $value = GetValue($id);
        // Erzwingt ein erneutes VM_UPDATE, damit die Kachel im WebFront den
        // (unveränderten) Wert erneut anzeigt, statt optisch "hängen" zu bleiben.
        SetValue($id, $value);
    }

    /**
     * Aktualisiert die Netzdruck-Statusvariable: entweder aus der
     * verknüpften externen Sensor-Variable (reale Hardware) oder, falls
     * keine verknüpft ist, aus der internen Simulation (nur relevant
     * während einer laufenden Füllphase, siehe StartFillPhase/Tick).
     */
    private function RefreshNetworkPressure(): void
    {
        $pressureVarID = $this->ReadPropertyInteger('NetworkPressureVariableID');
        if ($pressureVarID > 0 && @IPS_VariableExists($pressureVarID)) {
            $this->SetValue('NetworkPressure', (float) GetValue($pressureVarID));
            return;
        }

        // Simulationsmodus: während einer Füllphase steigt der Druck mit
        // FillRate an (siehe StartFillPhase/TickFillSimulation), außerhalb
        // einer Füllphase bleibt der zuletzt simulierte Wert stehen.
        $phase = $this->GetValue('Phase');
        if (in_array($phase, ['fill1', 'fill2'], true)) {
            $target = (float) $this->GetBuffer('FillTarget');
            $current = (float) $this->GetBuffer('SimulatedPressure');
            $rate = (float) $this->ReadPropertyFloat('FillRate');
            $current = min($target, $current + $rate * ($this->GetTimerIntervalSeconds()));
            $this->SetBuffer('SimulatedPressure', (string) $current);
            $this->SetValue('NetworkPressure', $current);
        }
    }

    private function GetTimerIntervalSeconds(): float
    {
        return 0.25; // entspricht dem festen SequenceTimer-Intervall (250 ms), siehe StartSequenceTimer()
    }

    private function StartFillPhase(float $Target): void
    {
        $this->SetBuffer('FillTarget', (string) $Target);
        $this->RefreshNetworkPressure();
    }

    private function StartCountdown(int $DurationSeconds): void
    {
        $this->SetBuffer('PhaseEndTime', (string) (time() + $DurationSeconds));
        $this->SetValue('RemainingSeconds', $DurationSeconds);
    }

    /** Aktualisiert RemainingSeconds; gibt true zurück, sobald die Zeit abgelaufen ist. */
    private function TickCountdown(): bool
    {
        $end = (int) $this->GetBuffer('PhaseEndTime');
        $remaining = max(0, $end - time());
        $this->SetValue('RemainingSeconds', $remaining);
        return $remaining <= 0;
    }

    private function StartSequenceTimer(): void
    {
        $this->SetTimerInterval('SequenceTimer', 250);
    }

    private function StopSequenceTimer(): void
    {
        $this->SetTimerInterval('SequenceTimer', 0);
    }

    /**
     * Ermittelt den Sammelstatus des GKS-Blocks (identisch zur Logik im
     * Burghausen-Dashboard, computeGksStatusForGas):
     * - Alarm (rot):    alle drei Ventile zu
     * - Störung (gelb):  Anlauf-/Prüfphase ODER Netzdruck im Warn-/Alarmbereich
     * - OK (grün):       Haupt- (V2) UND Bypassventil (V3) offen, kein Fehler
     * - sonst (gelb):    Zwischenzustand (z. B. nur V1 offen außerhalb der Sequenz)
     */
    private function RecomputeBlockStatus(): void
    {
        $v1 = $this->GetValue('V1');
        $v2 = $this->GetValue('V2');
        $v3 = $this->GetValue('V3');

        if (!$v1 && !$v2 && !$v3) {
            $this->SetValue('BlockStatus', self::STATUS_ALARM);
            return;
        }

        $phase = $this->GetValue('Phase');
        $inStartup = in_array($phase, ['fill1', 'test1', 'fill2', 'test2'], true);

        $pressure = $this->GetValue('NetworkPressure');
        $alarmBelow = (float) $this->ReadPropertyFloat('PressureAlarmBelow');
        $warnBelow = (float) $this->ReadPropertyFloat('PressureWarnBelow');
        $pressureFault = ($alarmBelow > 0 && $pressure < $alarmBelow)
            || ($warnBelow > 0 && $pressure < $warnBelow);

        if ($inStartup || $pressureFault) {
            $this->SetValue('BlockStatus', self::STATUS_WARNING);
            return;
        }

        if ($v2 && $v3) {
            $this->SetValue('BlockStatus', self::STATUS_OK);
            return;
        }

        $this->SetValue('BlockStatus', self::STATUS_WARNING);
    }

    // ================================================================
    // ---- Visualisierung (WebFront-Kachel dieser Instanz) ----
    // ================================================================

    /**
     * Liefert eine kompakte, live aktualisierte SVG-Darstellung des
     * GKS-Blocks (Diagramm mit V1/V2/V3, Netzdruck, Phase, Timer sowie
     * Freigabe-/Sperre-Buttons) für die WebFront-Kachel dieser Instanz -
     * gestalterisch identisch zum GKS-Block-Popup/-Übersichtsbild des
     * Burghausen-Dashboards.
     *
     * Aus Kompatibilitätsgründen verwendet die eingebettete Aktualisierung
     * denselben JSON-RPC-Endpunkt (/api/, IPS_GetValue / RequestAction), den
     * auch das bestehende Dashboard (HTML-Box) bereits nutzt - dieselbe,
     * nachweislich funktionierende Technik statt einer möglicherweise
     * versionsabhängigen internen Visualisierungs-API.
     */
    public function GetVisualizationTile()
    {
        $instanceID = $this->InstanceID;
        $gasSymbol = htmlspecialchars($this->ReadPropertyString('GasSymbol'));
        $gasName = htmlspecialchars($this->T($this->ReadPropertyString('GasName'), $this->ReadPropertyString('GasNameEn')) ?: $gasSymbol);

        $idV1 = $this->GetIDForIdent('V1');
        $idV2 = $this->GetIDForIdent('V2');
        $idV3 = $this->GetIDForIdent('V3');
        $idV1Mode = $this->GetIDForIdent('V1Mode');
        $idV2Mode = $this->GetIDForIdent('V2Mode');
        $idV3Mode = $this->GetIDForIdent('V3Mode');
        $idPressure = $this->GetIDForIdent('NetworkPressure');
        $idPhase = $this->GetIDForIdent('Phase');
        $idRemaining = $this->GetIDForIdent('RemainingSeconds');
        $idTotal = $this->GetIDForIdent('TotalRuntime');
        $idHint = $this->GetIDForIdent('ReleaseHint');
        $idStatus = $this->GetIDForIdent('BlockStatus');
        $idFreigeben = $this->GetIDForIdent('Freigeben');
        $idSperren = $this->GetIDForIdent('Sperren');

        $tileID = 'gksb_' . $instanceID;

        return <<<HTML
<div id="{$tileID}" class="gksb-tile">
  <style>
    #{$tileID} {
      font-family: "Segoe UI","Roboto","Helvetica Neue",Arial,sans-serif;
      color: #0B2C5C;
      max-width: 480px;
    }
    #{$tileID} .gksb-header { font-weight: 700; font-size: 15px; margin-bottom: 8px; }
    #{$tileID} .gksb-diagram { width: 100%; }
    #{$tileID} .gksb-diagram svg { width: 100%; height: auto; display: block; }
    #{$tileID} .gksb-phase {
      margin-top: 10px; font-size: 13px; font-weight: 700;
      background: #F1F3F4; display: inline-block; padding: 5px 10px; border-radius: 6px;
    }
    #{$tileID} .gksb-timers { display: flex; gap: 18px; margin-top: 10px; font-size: 12px; }
    #{$tileID} .gksb-timers b { display: block; font-size: 15px; }
    #{$tileID} .gksb-buttons { margin-top: 12px; display: flex; gap: 8px; }
    #{$tileID} .gksb-btn {
      flex: 1; padding: 9px 12px; border-radius: 6px; border: none; cursor: pointer;
      font-weight: 700; font-size: 13px; color: #fff;
    }
    #{$tileID} .gksb-btn--release { background: #2FAE60; }
    #{$tileID} .gksb-btn--lock { background: #E53E2B; }
    #{$tileID} .gksb-hint { margin-top: 8px; font-size: 12px; font-weight: 700; color: #E53E2B; min-height: 14px; }
  </style>

  <div class="gksb-header">GKS-Block – {$gasName} ({$gasSymbol})</div>
  <div class="gksb-diagram" id="{$tileID}_diagram"></div>
  <div class="gksb-phase" id="{$tileID}_phase">–</div>
  <div class="gksb-timers">
    <div>Gesamtlaufzeit<b id="{$tileID}_total">--:--</b></div>
    <div>Restlaufzeit<b id="{$tileID}_remaining">--:--</b></div>
  </div>
  <div class="gksb-buttons">
    <button class="gksb-btn gksb-btn--release" onclick="{$tileID}_action({$idFreigeben}, true)">Freigabe GKS</button>
    <button class="gksb-btn gksb-btn--lock" onclick="{$tileID}_action({$idSperren}, true)">Sperre GKS</button>
  </div>
  <div class="gksb-hint" id="{$tileID}_hint"></div>

  <script>
  (function(){
    var ids = {
      v1: {$idV1}, v2: {$idV2}, v3: {$idV3},
      v1Mode: {$idV1Mode}, v2Mode: {$idV2Mode}, v3Mode: {$idV3Mode},
      pressure: {$idPressure}, phase: {$idPhase}, remaining: {$idRemaining},
      total: {$idTotal}, hint: {$idHint}, status: {$idStatus}
    };

    function rpc(method, params){
      return fetch('/api/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ jsonrpc: '2.0', method: method, params: params, id: 1 })
      }).then(function(r){ return r.json(); }).then(function(d){ return d.result; });
    }
    function getValue(id){ return rpc('IPS_GetValue', { VariableID: id }); }

    window.{$tileID}_action = function(id, value){
      rpc('RequestAction', { VariableID: id, Value: value });
    };

    function fmtTime(totalSeconds){
      totalSeconds = Math.max(0, totalSeconds|0);
      var m = Math.floor(totalSeconds/60), s = totalSeconds % 60;
      return (m<10?'0':'')+m+':'+(s<10?'0':'')+s;
    }

    var phaseLabels = {
      idle:'Bereit', locked:'Gesperrt - alle Ventile zu', released:'Freigegeben',
      fill1:'Netzfüllung …', test1:'Prüfzeit 1 läuft …', fill2:'Netzfüllung …', test2:'Prüfzeit 2 läuft …'
    };

    function valveGlyph(cx, label, isOpen, isHand){
      var color = isOpen ? '#2FAE60' : '#E53E2B';
      var w = 16, h = 20, top = 40, bottom = top + h, mid = (top+bottom)/2;
      var x = cx - w/2;
      var hand = isHand
        ? '<circle cx="'+(x+w+6)+'" cy="'+(top-4)+'" r="7" fill="#FFE7A6" stroke="#8A5A00" stroke-width="1"/>'+
          '<text x="'+(x+w+6)+'" y="'+(top-1)+'" text-anchor="middle" font-size="8" font-weight="800" fill="#8A5A00">H</text>'
        : '';
      return '<polygon points="'+x+','+top+' '+(x+w)+','+top+' '+cx+','+mid+'" fill="'+color+'" stroke="#0B2C5C" stroke-width="1.4"/>' +
             '<polygon points="'+x+','+bottom+' '+(x+w)+','+bottom+' '+cx+','+mid+'" fill="'+color+'" stroke="#0B2C5C" stroke-width="1.4"/>' +
             '<text x="'+cx+'" y="'+(bottom+14)+'" text-anchor="middle" font-size="11" font-weight="700" fill="#0B2C5C">'+label+'</text>' +
             hand;
    }

    function render(v){
      var svg = '<svg viewBox="0 0 300 90" xmlns="http://www.w3.org/2000/svg">' +
        valveGlyph(60, 'V1', v.v1, v.v1Mode===1) +
        valveGlyph(150, 'V2', v.v2, v.v2Mode===1) +
        valveGlyph(240, 'V3', v.v3, v.v3Mode===1) +
        '<text x="298" y="14" text-anchor="end" font-size="11" font-weight="700" fill="#0B2C5C">' +
          (typeof v.pressure === 'number' ? v.pressure.toFixed(1) : '–') + ' bar</text>' +
        '</svg>';
      document.getElementById('{$tileID}_diagram').innerHTML = svg;
      document.getElementById('{$tileID}_phase').textContent = phaseLabels[v.phase] || v.phase || '–';
      document.getElementById('{$tileID}_remaining').textContent = (v.phase==='test1'||v.phase==='test2') ? fmtTime(v.remaining) : '--:--';
      document.getElementById('{$tileID}_total').textContent = (v.total>0) ? fmtTime(v.total) : '--:--';
      document.getElementById('{$tileID}_hint').textContent = v.hint || '';
    }

    function refresh(){
      Promise.all([
        getValue(ids.v1), getValue(ids.v2), getValue(ids.v3),
        getValue(ids.v1Mode), getValue(ids.v2Mode), getValue(ids.v3Mode),
        getValue(ids.pressure), getValue(ids.phase), getValue(ids.remaining),
        getValue(ids.total), getValue(ids.hint)
      ]).then(function(r){
        render({
          v1:r[0], v2:r[1], v3:r[2],
          v1Mode:r[3], v2Mode:r[4], v3Mode:r[5],
          pressure:r[6], phase:r[7], remaining:r[8], total:r[9], hint:r[10]
        });
      })['catch'](function(e){ console.error('GKS-Block Kachel: Fehler beim Aktualisieren', e); });
    }

    refresh();
    setInterval(refresh, 1000);
  })();
  </script>
</div>
HTML;
    }
}
