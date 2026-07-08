<?php

declare(strict_types=1);

/** Generell funktions */
require_once __DIR__ . '/../libs/_traits.php';

/**
 * Class ClimateControl
 */
class ClimateControl extends IPSModuleStrict
{
    // -------------------------------------------------------------------------
    // Traits
    // -------------------------------------------------------------------------

    use DebugHelper;
    use FormatHelper;
    use VariableHelper;
    use VersionHelper;

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** @var int Min IPS Object ID  */
    private const IPS_MIN_ID = 10000;

    /** @var int Default color for cold temperature (RGB integer) */
    private const COLOR_COLD = 0x11A0F3;

    /** @var int Default color for warm temperature (RGB integer) */
    private const COLOR_WARM = 0xF35A2C;

    /** @var int Default color for mode indicators (RGB integer) */
    private const COLOR_MODE = 0x11A0F3;

    /** @var int Default color for status indicators (RGB integer) */
    private const COLOR_STATUS = 0xFFC107;

    /** @var string Indicator for status actions */
    private const ACTION_STATUS = 'status';

    /** @var string Indicator for temperature actions */
    private const ACTION_TEMP = 'temperature';

    /** @var string Indicator for mode actions */
    private const ACTION_MODE = 'mode';

    /** @var list<string> List of all registered variables */
    private const REG_VARIABLES = [
        'tempActual',
        'tempTarget',
        'relativeHumidity',
        'valveLevel',
        'modeActive'
    ];

    // -------------------------------------------------------------------------
    // Methods
    // -------------------------------------------------------------------------

    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting IP-Symcon.
     * Therefore, status variables and module properties which the module requires permanently should be created here.
     *
     * @return void
     */
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        // Climate data
        $this->RegisterPropertyInteger('tempActual', 1);
        $this->RegisterPropertyInteger('tempTarget', 1);
        $this->RegisterPropertyInteger('valveLevel', 1);
        $this->RegisterPropertyInteger('relativeHumidity', 1);
        $this->RegisterPropertyString('statusData', '[]');
        $this->RegisterPropertyInteger('modeActive', 1);
        $this->RegisterPropertyString('modeData', '[]');

        // Visu settings
        $this->RegisterPropertyInteger('colorCold', self::COLOR_COLD);
        $this->RegisterPropertyInteger('colorWarm', self::COLOR_WARM);
        $this->RegisterPropertyBoolean('statusLabels', false);
        $this->RegisterPropertyBoolean('modeLabels', true);

        // Additional settings
        $this->RegisterPropertyInteger('scriptAction', 1);

        // Set visualization type to 1, as we want to offer HTML
        $this->SetVisualizationType(1);
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting IP-Symcon.
     *
     * @return void
     */
    public function Destroy(): void
    {
        parent::Destroy();
    }

    /**
     * The content can be overwritten in order to transfer a self-created configuration page.
     * This way, content can be generated dynamically.
     * In this case, the "form.json" on the file system is completely ignored.
     *
     * @return string Content of the configuration page.
     */
    public function GetConfigurationForm(): string
    {
        // Get Form
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Extract Version
        $ins = IPS_GetInstance($this->InstanceID);
        $mod = IPS_GetModule($ins['ModuleInfo']['ModuleID']);
        $lib = IPS_GetLibrary($mod['LibraryID']);
        $form['actions'][1]['items'][2]['caption'] = sprintf('v%s.%d', $lib['Version'], $lib['Build']);

        //$this->LogDebug(__FUNCTION__, $form);
        return json_encode($form);
    }

    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        //Delete all references in order to readd them
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        //Delete all registrations in order to readd them
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        //Register variable references & update messages
        foreach (self::REG_VARIABLES as $property) {
            $id = $this->ReadPropertyInteger($property);
            if (IPS_VariableExists($id)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        $statusData = json_decode($this->ReadPropertyString('statusData'), true) ?? [];
        foreach ($statusData as $item) {
            $variable = $item['variable'] ?? 1;
            if (IPS_VariableExists($variable)) {
                $this->RegisterReference($variable);
                $this->RegisterMessage($variable, VM_UPDATE);
            }
        }

        //Register script references
        $script = $this->ReadPropertyInteger('scriptAction');
        if (IPS_ScriptExists($script)) {
            $this->RegisterReference($script);
        }

        // Send a complete update message to the display, as parameters may have changed
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());

        // Set status
        $this->SetStatus(102);
    }

    /**
     * The content of the function can be overwritten in order to carry out own reactions to certain messages.
     * The function is only called for registered MessageIDs/SenderIDs combinations.
     *
     * data[0] = new value
     * data[1] = value changed?
     * data[2] = old value
     * data[3] = timestamp.
     *
     * @param int   $timestamp Continuous counter timestamp
     * @param int   $sender    Sender ID
     * @param int   $message   ID of the message
     * @param array{0:mixed,1:bool,2:mixed,3:int} $data Data of the message
     *
     * @return void
     */
    public function MessageSink(int $timestamp, int $sender, int $message, array $data): void
    {
        // check if state really changed ?
        if ($data[1] != true) {
            return;
        }

        if ($message === VM_UPDATE) {
            $found = false;
            $this->SendDebug(__FUNCTION__, "Update of $sender = $data[0]", 0);
            foreach (self::REG_VARIABLES as $property) {
                if ($this->ReadPropertyInteger($property) === $sender) {
                    $value = $data[0];

                    // Boolean valve drive: treat as a 0%/100% edge case of the
                    // standard 0–100% scale rather than as true/false
                    if ($property === 'valveLevel' && is_bool($value)) {
                        $value = $value ? 100.0 : 0.0;
                    }

                    $this->UpdateVisualizationValue(json_encode([
                        $property => $value,
                    ]));
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $statusData = json_decode($this->ReadPropertyString('statusData'), true) ?? [];
                $side = 0;
                foreach ($statusData as $item) {
                    if (($item['variable'] ?? 1) === $sender) {
                        $this->UpdateVisualizationValue(json_encode([
                            $side == 0 ? 'statusLeft' : 'statusRight' => $data[0],
                        ]));
                        break;
                    }
                    $side++;
                }
            }
        }
    }
    /**
     * Is called when, for example, a button is clicked in the visualization.
     *
     * @param string $ident Ident of the variable
     * @param mixed $value The value to be set
     *
     * @return void
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        // Debug output
        $this->SendDebug(__FUNCTION__, $ident . ' => ' . $value, 0);

        switch ($ident) {
            case 'OnAnalyzeVariable':
                $this->ReadModesFromVariable($value);
                break;
            case 'OnClearList':
                $this->ClearModeList($value);
                break;
            case 'temperature':
                $this->UpdateTemperature($value);
                break;
            case 'mode':
                $this->UpdateMode($value);
                break;
            default:
                if (str_starts_with($ident, 'status_')) {
                    $variable = (int) substr($ident, 7);
                    $this->UpdateStatus($variable, (bool) $value);
                } else {
                    $this->LogDebug(__FUNCTION__, 'There was no reaction to the action.');
                }
        }

        // Send a complete update message to the display, as parameters may have changed
        // $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        return;
    }

    /**
     * If the HTML-SDK is to be used, this function must be overwritten in order to return the HTML content.
     *
     * @return string Initial display of a representation via HTML SDK
     */
    public function GetVisualizationTile(): string
    {
        // Add a script to set the values when loading, analogous to changes at runtime
        // Although the return from GetFullUpdateMessage is already JSON-encoded, json_encode is still executed a second time
        // This adds quotation marks to the string and any quotation marks within it are escaped correctly
        $handling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        // Add static HTML from file
        $module = file_get_contents(__DIR__ . '/module.html');
        // Important: $initialHandling at the end, as the handleMessage function is only defined in the HTML
        return $module . $handling;
    }

    /**
     * Clear mode list
     *
     * @param bool $delete Delete mode list
     *
     * @return void
     */
    private function ClearModeList(bool $delete): void
    {
        if (!$delete) {
            return;
        }

        $this->UpdateFormField('modeData', 'values', json_encode([]));
    }

    /**
     * Get variable value or null
     *
     * @param string $property Property name
     */
    private function GetValueOrNull(string $property): mixed
    {
        $variable = $this->ReadPropertyInteger($property);
        return ($variable >= self::IPS_MIN_ID && IPS_VariableExists($variable)) ? GetValue($variable) : null;
    }

    /**
     * Resolve valve level
     *
     * @param int $id Variable ID
     *
     * @return float|null
     */
    private function ResolveValveLevel(int $id): ?float
    {
        if ($id < self::IPS_MIN_ID || !IPS_VariableExists($id)) {
            return null;
        }

        $variable = IPS_GetVariable($id);
        $value = GetValue($id);

        if ($variable['VariableType'] === VARIABLETYPE_BOOLEAN) {
            // Simple On/Off-Switch, return 100% or 0%
            return $value ? 100.0 : 0.0;
        }

        return (float) $value;
    }

    /**
     * Read modes from variable
     *
     * @param string $value Mode data & variable ID
     *
     * @return void
     */
    private function ReadModesFromVariable(string $value): void
    {
        // extract the last line from the list, which contains the selected variable ID
        $list = json_decode($value, true);

        // how many lines in the list?
        $last = count($list);
        // last line has the selected index
        $variable = $list[$last - 1];

        if ($variable <= self::IPS_MIN_ID || !IPS_VariableExists($variable)) {
            $this->EchoMessage($this->Translate('Please select a variable first.'));
            return;
        }

        // 1) Try this first: modern presentation (IPS 8+)
        $rows = $this->ExtractOptionsFromPresentation($variable);

        // 2) Fallback: classic variable profile (prior to IPS 6.x)
        if ($rows === null) {
            $rows = $this->ExtractOptionsFromProfile($variable);
        }

        if ($rows === null) {
            $this->EchoMessage($this->Translate('Selected variable has neither a presentation nor a profile with associations.'));
            return;
        }

        // Retain the existing name/icon/colour for each value so that re-
        // loading does not overwrite manual adjustments
        // delete last entry from list with the variable ID, as it is not a mode
        unset($list[$last - 1]);

        $byValue = [];
        foreach ($list as $row) {
            $byValue[(string) $row['value']] = $row;
        }

        $result = [];
        foreach ($rows as $row) {
            $value = $row['Value'];
            $name = $byValue[(string) $value]['name'] ?? $row['Name'];
            $result[] = [
                'name'  => $name,
                'value' => $value,
                'icon'  => $byValue[(string) $value]['icon'] ?? ($row['Icon'] ?? ''),
                'color' => $byValue[(string) $value]['color'] ?? ($row['Color'] ?? -1)
            ];
        }

        $this->UpdateFormField('modeData', 'values', json_encode($result));
    }

    /**
     * Reads options from a modern presentation, if available.
     * Returns `null` if the variable does not have an enumeration-like presentation.
     *
     * @param int $id Variable ID.
     *
     * @return list<array<string,mixed>>|null Array of options, or `null` if no enumeration-like presentation is available.
     */
    private function ExtractOptionsFromPresentation(int $id): ?array
    {
        $variable = IPS_GetVariable($id);
        $presentation = $variable['VariablePresentation'] ?? [];
        $presentation = $variable['VariablePresentation'] ?: $variable['VariableCustomPresentation'];

        if (empty($presentation)) {
            return null;
        }

        // Check only PRESENTATION or PRESENTATION + TEMPLATE
        $keys = array_keys($presentation);
        if (in_array('PRESENTATION', $keys, true) && count(array_diff($keys, ['PRESENTATION', 'TEMPLATE'])) === 0) {
            $presentation = IPS_GetVariablePresentation($id);
        }

        $rows = [];

        // over OPTIONS key, if available, as it is the most specific one
        if (isset($presentation['OPTIONS'])) {
            $options = $presentation['OPTIONS'];
            if (is_string($options)) {
                $options = json_decode($options, true);
            }
            if (!is_array($options) || count($options) === 0) {
                return null;
            }
            foreach ($options as $option) {
                $rows[] = [
                    'Name'  => $option['Caption'] ?? $option['Name'] ?? (string) ($option['Value'] ?? ''),
                    'Value' => $option['Value'] ?? null,
                    'Icon'  => $option['IconValue'] ?? $option['Icon'] ?? '',
                    'Color' => $option['Color'] ?? self::COLOR_MODE,
                ];
            }
            return $rows;
        }

        // over INTERVALS key, if available, as it is the most specific one
        if (isset($presentation['INTERVALS'])) {
            $intervals = $presentation['INTERVALS'];
            if (is_string($intervals)) {
                $intervals = json_decode($intervals, true);
            }
            if (!is_array($intervals) || count($intervals) === 0) {
                return null;
            }
            foreach ($intervals as $interval) {
                $rows[] = [
                    'Name'  => $interval['ConstantValue'] ?? $interval['Name'] ?? (string) ($interval['Value'] ?? ''),
                    'Value' => $interval['IntervalMinValue'] ?? null,
                    'Icon'  => $interval['IconValue'] ?? $interval['Icon'] ?? '',
                    'Color' => $interval['ColorValue'] ?? self::COLOR_MODE,
                ];
            }
            return $rows;
        }

        return null;
    }

    /**
     * Fallback to the classic variable profile (prior to IPS 6.0).
     * Returns null if no profile with associations has been set.
     *
     * @param int $id Variable ID.
     *
     * @return list<array<string,mixed>>|null Array of options, or `null` if no enumeration-like presentation is available.
     */
    private function ExtractOptionsFromProfile(int $id): ?array
    {
        $variable = IPS_GetVariable($id);
        $profileName = $variable['VariableCustomProfile'] ?: $variable['VariableProfile'];

        if ($profileName === '' || !IPS_VariableProfileExists($profileName)) {
            return null;
        }

        $profile = IPS_GetVariableProfile($profileName);
        if (empty($profile['Associations'])) {
            return null;
        }

        $rows = [];
        foreach ($profile['Associations'] as $assoc) {
            $rows[] = [
                'Name'  => $assoc['Name'],
                'Value' => $assoc['Value'],
                'Icon'  => $assoc['Icon'] ?? '',
                'Color' => $assoc['Color'] ?? self::COLOR_MODE
            ];
        }

        return $rows;
    }

    /**
     * Update target temperature.
     *
     * @param float $value New temperature value
     *
     * @return void
     */
    private function UpdateTemperature(float $value): void
    {
        $variable = $this->ReadPropertyInteger('tempTarget');
        if (IPS_VariableExists($variable)) {
            if (HasAction($variable)) {
                RequestAction($variable, $value);
            } else {
                $ret = @SetValue($variable, $value);
                if ($ret === false) {
                    $this->LogDebug(__FUNCTION__, "Failed to set value for variable $variable");
                }
            }
            $this->ForwardToScript(self::ACTION_TEMP, $variable, $value);
        }
    }

    /**
     * Update active mode.
     *
     * @param mixed $value New mode value
     *
     * @return void
     */
    private function UpdateMode(mixed $value): void
    {
        $variable = $this->ReadPropertyInteger('modeActive');
        if (IPS_VariableExists($variable)) {
            if (HasAction($variable)) {
                RequestAction($variable, $value);
            } else {
                $ret = @SetValue($variable, $value);
                if ($ret === false) {
                    $this->LogDebug(__FUNCTION__, "Failed to set value for variable $variable");
                }
            }
            $this->ForwardToScript(self::ACTION_MODE, $variable, $value);
        }
    }

    /**
     * Update status state for a given variable.
     *
     * @param int $variable Variable ID to update
     * @param bool $value New status value
     *
     * @return void
     */
    private function UpdateStatus(int $variable, bool $value): void
    {
        $statusData = json_decode($this->ReadPropertyString('statusData'), true) ?? [];
        foreach ($statusData as &$item) {
            if ($item['variable'] ?? 1 === $variable) {
                if (IPS_VariableExists($variable)) {
                    if (HasAction($variable)) {
                        RequestAction($variable, $value);
                    } else {
                        $ret = @SetValue($variable, $value);
                        if ($ret === false) {
                            $this->LogDebug(__FUNCTION__, "Failed to set value for variable $variable");
                        }
                    }
                    $this->ForwardToScript(self::ACTION_STATUS, $variable, $value);
                }
                break;
            }
        }
    }

    /**
     * Forward requested action values to script
     *
     * @param string $type Type of action (temperature, mode, status)
     * @param int $variable Variable ID to update
     * @param mixed $value New value to set
     *
     * @return void
     */
    private function ForwardToScript(string $type, int $variable, mixed $value): void
    {
        $script = $this->ReadPropertyInteger('scriptAction');
        if (IPS_ScriptExists($script)) {
            $params = [
                'INSTANCE'  => $this->InstanceID,
                'TIMESTAMP' => time(),
                'TYPE'      => $type,
                'VARIABLE'  => $variable,
                'VALUE'     => $value
            ];
            IPS_RunScriptEx($script, $params);
        }
    }
    /**
     * Generate a message that updates all elements in the HTML display.
     *
     * @return string JSON encoded message information
     */
    private function GetFullUpdateMessage(): string
    {
        $statusData = json_decode($this->ReadPropertyString('statusData'), true) ?? [];
        foreach ($statusData as &$item) {
            $item['color'] = $this->GetColorFormatted($item['color'] ?? self::COLOR_STATUS);
            if (IPS_VariableExists($item['variable'] ?? 1)) {
                $item['value'] = GetValue($item['variable']);
            } else {
                $item['value'] = false;
            }
        }

        $modeData = json_decode($this->ReadPropertyString('modeData'), true) ?? [];
        foreach ($modeData as &$item) {
            $item['color'] = $this->GetColorFormatted($item['color'] ?? self::COLOR_MODE);
            $this->LogDebug(__FUNCTION__, 'Mode item: ' . print_r($item, true));
        }

        $result = [];
        $result['colorCold'] = $this->GetColorFormatted($this->ReadPropertyInteger('colorCold'));
        $result['colorWarm'] = $this->GetColorFormatted($this->ReadPropertyInteger('colorWarm'));
        $result['tempActual'] = $this->GetValueOrNull('tempActual');
        $result['tempTarget'] = $this->GetValueOrNull('tempTarget');
        $result['valveLevel'] = $this->ResolveValveLevel($this->ReadPropertyInteger('valveLevel'));
        $result['relativeHumidity'] = $this->GetValueOrNull('relativeHumidity');
        $result['statusData'] = $statusData;
        $result['modeActive'] = $this->GetValueOrNull('modeActive');
        $result['modeData'] = $modeData;
        $result['statusLabels'] = $this->ReadPropertyBoolean('statusLabels');
        $result['modeLabels'] = $this->ReadPropertyBoolean('modeLabels');

        $this->LogDebug(__FUNCTION__, print_r($result, true));

        // send it
        return json_encode($result);
    }

    /**
     * Show message via popup
     *
     * @param string $caption echo message
     *
     * @return void
     */
    private function EchoMessage(string $caption): void
    {
        $this->UpdateFormField('EchoMessage', 'caption', $this->Translate($caption));
        $this->UpdateFormField('EchoPopup', 'visible', true);
    }
}