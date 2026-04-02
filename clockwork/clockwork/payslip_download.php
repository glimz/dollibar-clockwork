<?php
/**
 * Secure download endpoint for Clockwork generated payslip PDFs.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && $j > 0) {
	$res = @include substr($tmp, 0, $i + 1)."/main.inc.php";
}
if (!$res) {
	die('Include of main fails');
}

if (!isModEnabled('clockwork')) accessforbidden();
require_once DOL_DOCUMENT_ROOT.'/custom/clockwork/class/clockworkcompliance.class.php';

$complianceId = GETPOSTINT('compliance_id');
if ($complianceId <= 0) accessforbidden();

$sql = 'SELECT c.fk_user, m.pdf_file';
$sql .= ' FROM '.MAIN_DB_PREFIX.'clockwork_monthly_compliance c';
$sql .= ' JOIN '.MAIN_DB_PREFIX.'clockwork_payslip_map m ON m.fk_compliance = c.rowid';
$sql .= ' WHERE c.entity = '.((int) $conf->entity);
$sql .= ' AND c.rowid = '.((int) $complianceId);
$resql = $db->query($sql);
if (!$resql) accessforbidden();
$obj = $db->fetch_object($resql);
if (!$obj || empty($obj->pdf_file)) accessforbidden();

$ownerId = (int) $obj->fk_user;
$canReadAll = $user->hasRight('clockwork', 'readall') || $user->hasRight('clockwork', 'manage');
$isOwner = ((int) $user->id === $ownerId);
if (!$canReadAll && !$isOwner) {
	accessforbidden();
}

$relative = ltrim((string) $obj->pdf_file, '/');
$absolute = DOL_DATA_ROOT.'/'.$relative;
if (empty($relative) || !is_readable($absolute)) {
	$service = new ClockworkCompliance($db);
	$salaryId = $service->getSalaryIdByComplianceId($complianceId);
	$render = $service->generatePayslipPdf($complianceId, $salaryId, true);
	if (empty($render['ok']) || empty($render['absolute']) || !is_readable($render['absolute'])) {
		accessforbidden();
	}
	$absolute = $render['absolute'];
}

$real = realpath($absolute);
$root = realpath(DOL_DATA_ROOT);
if ($real === false || $root === false || strpos($real, $root) !== 0) {
	accessforbidden();
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.basename($absolute).'"');
header('Content-Length: '.filesize($absolute));
readfile($absolute);
exit;
