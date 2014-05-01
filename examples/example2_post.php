<?

error_reporting(0);

require("../acrostix.php");

header("Content-type: text/plain");
$doc = ax_open_pdf_file("f1040ez.pdf");
if($doc) {
	$style = new AxTextStyle;
	$style->fontSize = 10;
	ax_fill_text_field($doc, 'f1-1', $_POST['f1-1'], $style);
	ax_fill_text_field($doc, 'f1-2', $_POST['f1-2'], $style);
	ax_fill_text_field($doc, 'f1-8', $_POST['f1-8'], $style, AX_RIGHT_JUSTIFY);
	ax_fill_text_field($doc, 'f1-9', $_POST['f1-9'], $style, AX_CENTER_JUSTIFY);
	ax_fill_text_field($doc, 'f1-10', $_POST['f1-10'], $style, AX_LEFT_JUSTIFY);
	ax_fill_text_field($doc, 'f1-3', $_POST['f1-3'], $style);
	ax_fill_text_field($doc, 'f1-4', $_POST['f1-4'], $style);
	ax_fill_text_field($doc, 'f1-11', $_POST['f1-11'], $style, AX_RIGHT_JUSTIFY);
	ax_fill_text_field($doc, 'f1-12', $_POST['f1-12'], $style, AX_CENTER_JUSTIFY);
	ax_fill_text_field($doc, 'f1-13', $_POST['f1-13'], $style, AX_LEFT_JUSTIFY);
	ax_fill_text_field($doc, 'f1-5', $_POST['f1-5'], $style);
	ax_fill_text_field($doc, 'f1-6', $_POST['f1-6'], $style, AX_CENTER_JUSTIFY);
	ax_fill_text_field($doc, 'f1-7', $_POST['f1-7'], $style);
	ax_set_checkbox($doc, 'c1-1', $_POST['c1-1']);
	ax_set_checkbox($doc, 'c1-2', $_POST['c1-2']);
	ax_set_radio_button($doc, 'c1-5', $_POST['c1-5']);
	ax_remove_form($doc);
	
	header("Content-type: application/pdf");
	ax_output_pdf_file($doc);	
}

?>
