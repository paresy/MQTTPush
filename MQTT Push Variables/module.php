<?php

declare(strict_types=1);
class MQTTPushVariables extends IPSModuleStrict
{
    public function Create(): void
    {
        $this->RegisterPropertyInteger("BaseID", -1);
        $this->RegisterPropertyString("BaseTopic", "");

        $this->ConnectParent('{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}');
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->UpdateSubscription();
    }

    public function ReceiveData($JSONString): string
    {
        return "";
    }

    public function UpdateSubscription(): void
    {
        //Delete all registrations in order to readd them
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $searchVariables = function($baseID) use (&$searchVariables) {
            if (IPS_ObjectExists($baseID)) {
                $ids = IPS_GetChildrenIDs($baseID);
                foreach ($ids as $id) {
                    $searchVariables($id);

                    if (IPS_VariableExists($id)) {
                        $this->RegisterMessage($id, VM_UPDATE);
                    }
                }
            }
        };

        //Traverse full tree from starting point
        $baseID = $this->ReadPropertyInteger("BaseID");
        if ($baseID >= 0) {
            $searchVariables($baseID);
        }
    }

    public function SendSnapshot(): void
    {
        $send = function($baseID) use (&$send, &$getLocation) {
            if (IPS_ObjectExists($baseID)) {
                $ids = IPS_GetChildrenIDs($baseID);
                foreach ($ids as $id) {
                    $send($id);

                    if (IPS_VariableExists($id)) {
                        $this->Send($this->GetLocation($id), GetValueFormatted($id));
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

        $this->SendDataToParent(json_encode($packet));
    }
}