<?php
$moduleId = 0;
if ($moduleId > 0 && isset($_IPS['VALUE']) && is_string($_IPS['VALUE'])) {
    SMLH_Ingest($moduleId, 'wp', base64_encode($_IPS['VALUE']));
}
