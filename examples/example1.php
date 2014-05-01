<?php

require("../acrostix.php");

$text = file_get_contents("example1.txt");
$doc = ax_open_pdf_file("template1.pdf");
$style = ax_create_standard_styles("Times-Roman", 16);
$region = ax_create_rectangular_region(1, 1, 7.5, 8);
$lines = ax_lay_out_text($text, $region, $style, AX_FULL_JUSTIFY);
ax_add_page_elements($doc->pages[0], $lines);

header("Content-type: application/pdf");
ax_output_pdf_file($doc);

?>