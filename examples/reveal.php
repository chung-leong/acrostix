<?php

require("../acrostix.php");

$doc = ax_open_pdf_file("f1040ez.pdf");
if($doc) {
	ax_reveal_form_fields($doc);
	
	header("Content-type: application/pdf");
	ax_output_pdf_file($doc);
}

?>