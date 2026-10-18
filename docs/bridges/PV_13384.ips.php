<?php
// Replace 0 with SymconSML instance ID; enable only during controlled migration.
$moduleId = 0;
if ($moduleId > 0 && isset($_IPS['VALUE']) && is_string($_IPS['VALUE'])) {
    SMLH_Ingest($moduleId, 'pv', base64_encode($_IPS['VALUE']));
}
