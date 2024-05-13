<?php

declare(strict_types=1);
class MQTTPushInstances extends IPSModuleStrict
{
    public function Create(): void
    {
        $this->RegisterPropertyInteger("BaseID", -1);
        $this->RegisterPropertyString("BaseTopic", "");
        $this->RegisterPropertyInteger("Interval", 5);

        $this->ConnectParent('{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}');

        $this->RegisterTimer("Push", 0, "MQP_SendSnapshot(\$_IPS['TARGET']);");
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->SetTimerInterval("Push", $this->ReadPropertyInteger("Interval") * 60 * 1000);
    }

    public function ReceiveData($JSONString): string
    {
        return "";
    }

    public function SendSnapshot(): void
    {
        $send = function($baseID) use (&$send, &$getLocation) {
            if (IPS_ObjectExists($baseID)) {
                $ids = IPS_GetChildrenIDs($baseID);
                foreach ($ids as $id) {
                    $send($id);

                    if (IPS_InstanceExists($id)) {
                        // Skip over non device instances
                        $i = IPS_GetInstance($id);
                        if ($i["ModuleInfo"]["ModuleType"] != MODULETYPE_DEVICE) {
                            continue;
                        }

                        // Variable to hold all of our values that have an ident
                        $values = [];

                        $subids = IPS_GetChildrenIDs($id);
                        foreach ($subids as $subid) {
                            if (IPS_VariableExists($subid)) {
                                $o = IPS_GetObject($subid);
                                $v = IPS_GetVariable($subid);

                                if ($v['VariableCustomProfile'] != '') {
                                    $profileName = $v['VariableCustomProfile'];
                                } else {
                                    $profileName = $v['VariableProfile'];
                                }

                                $unit = "";
                                if (IPS_VariableProfileExists($profileName)) {
                                    $p = IPS_GetVariableProfile($profileName);
                                    $unit = trim($p["Suffix"]);
                                }

                                // Only send variables that have an ident
                                if ($o["ObjectIdent"]) {
                                    $values[$o["ObjectIdent"]] = [
                                        "name" => $o["ObjectName"],
                                        "value" => GetValue($subid),
                                        "unit" => $unit,
                                        "timestamp" => date('Y-m-d\TH:i:s.Z\Z', $v["VariableUpdated"]),
                                    ];
                                }
                            }
                        }

                        if (count($values) > 0) {
                            // Special case for our internal SWHL use-case.
                            // Read the M-Bus device id from the name into the id field
                            // See: https://regex101.com/r/Jo3NYG/1
                            if (preg_match("/M-Bus .*\((\d+), [A-Z]+, .+\)/", IPS_GetName($id), $matches)) {
                                $deviceID = $matches[1];
                            }
                            else {
                                $deviceID = $id;
                            }

                            // Build content
                            $content = [
                                "id" => $deviceID,
                                "timestamp" => date('Y-m-d\TH:i:s.Z\Z', time()),
                                "payload" => $values,
                            ];

                            $this->Send($this->GetLocation($id), json_encode($content));
                        }
                    }
                }
            }
        };

        $baseID = $this->ReadPropertyInteger("BaseID");
        if ($baseID >= 0) {
            $send($baseID);
        }
    }

    private function GetLocation($id): string
    {
        $name = str_replace("/", "", IPS_GetName($id));
        if ($id > 0) {
            return $this->GetLocation(IPS_GetParent($id)) . "/" . $name . " (" . $id . ")";
        }
        else {
            return $name;
        }
    }

    private function Send(string $Topic, string $Payload): void
    {
        $baseTopic = $this->ReadPropertyString("BaseTopic");

        // Append slash if not already given and base topic is not empty
        if ($baseTopic !== "" && substr($baseTopic, -1) !== "/") {
            $baseTopic .= "/";
        }

        $packet['DataID'] = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
        $packet['PacketType'] = 3;
        $packet['QualityOfService'] = 0;
        $packet['Retain'] = false;
        $packet['Topic'] = $baseTopic . $Topic;
        $packet['Payload'] = bin2hex($Payload);

        $this->SendDebug($Topic, $Payload, 0);
        $this->SendDataToParent(json_encode($packet));
    }
}