<?php
require_once __DIR__ . '/../libs/Alignment.php';

class SymconSML extends IPSModule
{
    public function Create()
    {
        parent::Create();
        foreach (['AlignDelayMs'=>2000,'MaxGapMs'=>4000,'StaleSeconds'=>10,'PowerDelta'=>10,'PowerHeartbeat'=>5,'CounterDelta'=>10,'CounterHeartbeat'=>300] as $key=>$value) {
            $this->RegisterPropertyInteger($key,$value);
        }
        $this->RegisterPropertyBoolean('EnableOutputs',false);
        $this->RegisterPropertyBoolean('DebugRaw',false);
        foreach (['pv','grid','wp'] as $channel) {
            $this->RegisterAttributeString('samples_'.$channel,'[]');
            $this->RegisterAttributeFloat('seen_'.$channel,0.0);
            $this->RegisterAttributeInteger('count_'.$channel,0);
            $this->RegisterAttributeInteger('errors_'.$channel,0);
            $this->RegisterVariableInteger('rx_'.$channel,strtoupper($channel).' Telegramme','',70);
            $this->RegisterVariableInteger('err_'.$channel,strtoupper($channel).' Dekodierfehler','',71);
            $this->RegisterVariableBoolean('ok_'.$channel,strtoupper($channel).' Empfang OK','~Switch',72);
        }
        foreach ([
            'pv_power'=>'PV-Leistung (W)',
            'pv_export'=>'PV-Erzeugungsstand (Wh)',
            'grid_power'=>'Netzleistung (W)',
            'grid_import'=>'Netzbezug (Wh)',
            'grid_export'=>'Netzeinspeisung (Wh)',
            'wp_power'=>'Wärmepumpe Leistung (W)',
            'wp_t1'=>'Wärmepumpe T1 (Wh)',
            'wp_t2'=>'Wärmepumpe T2 (Wh)',
            'wp_total'=>'Wärmepumpe Gesamt (Wh)',
            'house_power'=>'Hausleistung zeitkorrigiert (W)',
            'house_power_5s'=>'Hausleistung 5s (W)',
            'house_power_60s'=>'Hausleistung 60s (W)',
            'house_energy'=>'Hausverbrauch rechnerisch (Wh)'
        ] as $ident=>$caption) {
            $this->RegisterVariableFloat($ident,$caption,'',10);
        }
        $this->RegisterVariableBoolean('aligned_valid','Hausleistung gültig','~Switch',20);
        $this->RegisterVariableFloat('skew','Letzter PV-Netz-Empfangsversatz (s)','',30);
        $this->RegisterVariableInteger('last_sync','Letzter gemeinsamer Messzeitpunkt (Unix)','~UnixTimestamp',40);
        $this->RegisterAttributeString('last_written','{}');
        $this->RegisterAttributeString('rolling','[]');
        $this->RegisterTimer('Tick',1000,'SMLH_Tick($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('Tick',1000);
    }

    public function Ingest(string $channel,string $base64): bool
    {
        if (!in_array($channel,['pv','grid','wp'],true)) return false;
        $binary = base64_decode($base64,true);
        if ($binary === false || $binary === '') return false;
        $lock = 'SymconSML_'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock,1000)) return false;
        try {
            $count = $this->ReadAttributeInteger('count_'.$channel)+1;
            $this->WriteAttributeInteger('count_'.$channel,$count);
            $this->SetValue('rx_'.$channel,$count);
            $values = $this->Decode($channel,$binary);
            if ($values === null) {
                $errors = $this->ReadAttributeInteger('errors_'.$channel)+1;
                $this->WriteAttributeInteger('errors_'.$channel,$errors);
                $this->SetValue('err_'.$channel,$errors);
                return false;
            }
            $now = microtime(true);
            $samples = json_decode($this->ReadAttributeString('samples_'.$channel),true) ?: [];
            $samples = SMLAlignment::append($samples,$now,$values,90.0);
            $this->WriteAttributeString('samples_'.$channel,json_encode($samples));
            $this->WriteAttributeFloat('seen_'.$channel,$now);
            $this->SetValue('ok_'.$channel,true);
            if ($this->ReadPropertyBoolean('EnableOutputs')) {
                foreach ($values as $field=>$value) {
                    $this->FilteredWrite($field,$value,str_contains($field,'power'));
                }
            }
            if ($this->ReadPropertyBoolean('DebugRaw')) {
                $this->SendDebug('SML '.$channel,'Fields: '.implode(',',array_keys($values)),0);
            }
            return true;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    public function Tick(): void
    {
        $lock='SymconSML_'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock,1000)) return;
        try {
            $now=microtime(true);
            $stale=max(3,$this->ReadPropertyInteger('StaleSeconds'));
            foreach (['pv','grid','wp'] as $channel) {
                $this->SetValue('ok_'.$channel,$now-$this->ReadAttributeFloat('seen_'.$channel)<=$stale);
            }
            if (!$this->ReadPropertyBoolean('EnableOutputs')) {
                $this->SetValue('aligned_valid',false);
                return;
            }
            $pv=json_decode($this->ReadAttributeString('samples_pv'),true) ?: [];
            $grid=json_decode($this->ReadAttributeString('samples_grid'),true) ?: [];
            if (!$pv || !$grid || $now-$this->ReadAttributeFloat('seen_pv')>$stale || $now-$this->ReadAttributeFloat('seen_grid')>$stale) {
                $this->SetValue('aligned_valid',false);
                return;
            }
            $this->SetValue('skew',$this->ReadAttributeFloat('seen_pv')-$this->ReadAttributeFloat('seen_grid'));
            $target=$now-max(0.5,$this->ReadPropertyInteger('AlignDelayMs')/1000.0);
            $gap=max(0.5,$this->ReadPropertyInteger('MaxGapMs')/1000.0);
            $pvPower=SMLAlignment::at($pv,'pv_power',$target,$gap);
            $gridPower=SMLAlignment::at($grid,'grid_power',$target,$gap);
            $pvEnergy=SMLAlignment::at($pv,'pv_export',$target,$gap);
            $import=SMLAlignment::at($grid,'grid_import',$target,$gap);
            $export=SMLAlignment::at($grid,'grid_export',$target,$gap);
            if ($pvPower===null || $gridPower===null || $pvEnergy===null || $import===null || $export===null) {
                $this->SetValue('aligned_valid',false);
                return;
            }
            // Net positive = import; negative = export. PV generation positive.
            $housePower=$pvPower+$gridPower;
            $houseEnergy=$pvEnergy+$import-$export;
            $this->SetValue('aligned_valid',true);
            $this->SetValue('last_sync',(int)$target);
            $this->FilteredWrite('house_power',$housePower,true);
            $this->FilteredWrite('house_energy',$houseEnergy,false);
            $rolling=json_decode($this->ReadAttributeString('rolling'),true) ?: [];
            $rolling[]=['t'=>$target,'w'=>$housePower];
            $rolling=array_values(array_filter($rolling,static function($p) use ($target) { return $p['t'] >= $target-61; }));
            $this->WriteAttributeString('rolling',json_encode($rolling));
            foreach ([5=>'house_power_5s',60=>'house_power_60s'] as $seconds=>$field) {
                $average=$this->TimeAverage($rolling,$target-$seconds,$target);
                if ($average!==null) $this->FilteredWrite($field,$average,true);
            }
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function TimeAverage(array $samples,float $start,float $end): ?float
    {
        if (count($samples)<2 || $samples[0]['t']>$start || end($samples)['t']<$end-1.5) return null;
        $sum=0.0;
        $duration=0.0;
        for ($i=1;$i<count($samples);$i++) {
            $a=$samples[$i-1];
            $b=$samples[$i];
            $lo=max($start,(float)$a['t']);
            $hi=min($end,(float)$b['t']);
            if ($hi<=$lo || $b['t']<=$a['t'] || ($b['t']-$a['t'])>4.0) continue;
            $slope=($b['w']-$a['w'])/($b['t']-$a['t']);
            $first=$a['w']+$slope*($lo-$a['t']);
            $last=$a['w']+$slope*($hi-$a['t']);
            $sum+=($first+$last)*0.5*($hi-$lo);
            $duration+=$hi-$lo;
        }
        return $duration>=($end-$start)*0.95 ? $sum/$duration : null;
    }

    private function FilteredWrite(string $ident,float $value,bool $power): void
    {
        if (!is_finite($value)) return;
        $last=json_decode($this->ReadAttributeString('last_written'),true) ?: [];
        $now=microtime(true);
        $delta=$power?$this->ReadPropertyInteger('PowerDelta'):$this->ReadPropertyInteger('CounterDelta');
        $timeout=$power?$this->ReadPropertyInteger('PowerHeartbeat'):$this->ReadPropertyInteger('CounterHeartbeat');
        if (!isset($last[$ident]) || abs($value-$last[$ident]['value']) >= $delta || $now-$last[$ident]['t'] >= $timeout) {
            $this->SetValue($ident,$value);
            $last[$ident]=['value'=>$value,'t'=>$now];
            $this->WriteAttributeString('last_written',json_encode($last));
        }
    }

    private function Decode(string $channel,string $binary): ?array
    {
        // Installation-specific OBIS offsets from the original scripts, NOT a generic SML parser.
        $hex=bin2hex($binary);
        $p=static function(string $obis,int $len,array $indices,int $bits,int $scale=5) use ($hex): ?float {
            $pos=strpos($hex,$obis);
            if ($pos===false) return null;
            $bytes=str_split(substr($hex,$pos+16,$len),2);
            if (!isset($bytes[$scale])) return null;
            $value='';
            foreach ($indices as $i) {
                if (!isset($bytes[$i])) return null;
                $value.=$bytes[$i];
            }
            $raw=hexdec($value);
            $signed=$raw >= (2**($bits-1)) ? $raw-(2**$bits) : $raw;
            $exponent=hexdec($bytes[$scale]);
            if ($exponent>=128) $exponent-=256;
            return $signed*(10**$exponent);
        };
        if ($channel==='pv') {
            $result=[
                'pv_export'=>$p('77070100020801ff',26,range(7,11),40),
                'pv_power'=>$p('77070100100700ff',24,range(7,10),32)
            ];
        } elseif ($channel==='grid') {
            $result=[
                'grid_import'=>$p('77070100010801ff',26,range(7,11),40),
                'grid_export'=>$p('77070100020801ff',26,range(7,11),40),
                'grid_power'=>$p('77070100100700ff',24,range(7,10),32)
            ];
        } else {
            $result=[
                'wp_power'=>$p('77070100100700ff',24,range(7,10),32),
                'wp_t1'=>$p('77070100010801ff',32,range(7,14),64),
                'wp_t2'=>$p('77070100010802ff',32,range(7,14),64),
                'wp_total'=>$p('77070100010800ff',38,range(11,18),64,9)
            ];
        }
        return in_array(null,$result,true) ? null : $result;
    }
}
