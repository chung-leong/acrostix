<?php

require("../acrostix.php");

$file = basename($_GET['name'] . ".pdf");
$doc = ax_open_pdf_file($file);
if($doc) {
	header("Content-type: application/pdf");
	ax_overlay_grid_on_document($doc);
	ax_output_pdf_file($doc);
}

?>