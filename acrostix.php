<?php

define('AX_LEFT_JUSTIFY',		0x00000000);
define('AX_RIGHT_JUSTIFY',		0x00000001);
define('AX_CENTER_JUSTIFY',		0x00000002);
define('AX_FULL_JUSTIFY',		0x00000004);

define('AX_TOP_ALIGN',			0x00000000);
define('AX_BOTTOM_ALIGN',		0x00000010);
define('AX_CENTER_ALIGN',		0x00000020);

define('AX_RETURN_LEFTOVER',	0x00001000);

define('AX_UNDERLINE',			0x00000001);
define('AX_DOUBLE_UNDERLINE',	0x00000002);
define('AX_LINE_THROUGH',		0x00000004);

define('AX_NORMAL',				0x00000000);
define('AX_SUPERSCRIPT',		0x00000001);
define('AX_SUBSCRIPT',			0x00000002);

define('AX_OVERFLOW_SIDES',		0x08000000);
define('AX_OVERFLOW_BOTTOM',	0x04000000);

define('AX_DASHED_LINE',	'[4 4] 2');
define('AX_DOTTED_LINE',	'[1 3] 0');

define('AX_INFINITE',	2147483647);		
define('AX_IN_PHP5', 	intval(PHP_VERSION) >= 5);
define('AX_FILE_ROOT',	dirname(__FILE__));

define('AX_ACROSTIX_CREATOR_STRING', "Acrostix 0.1");

class AxDocument {
	var $pages;
	var $info;
	var $formFields;
	var $PDFStructure;
}

class AxPage {
	var $elements;
	var $width;
	var $height;
	var $mediaBox;
	var $cropBox;
	var $rotate;
	var $contents;
	var $resources;	
	var $PDFStructure;
}

class AxText {
	var $left;
	var $bottom;
	var $text;
	var $width;
	var $height;
	var $style;
	var $kernings;
}

class AxFont {
	var $name;
	var $widths;
	var $kerningPairs;
	var $attributes;
	var $scaleFactor;
	var $encoding;
	var $PDFStructure;
	
	function __toString() {
		return print_r($this, true);
	}
}

class AxImage {
	var $x;
	var $y;
	var $width;
	var $height;
	var $stream;
}

class AxStream {
	var $data;
	var $dictionary;
}

class AxRegion {
	var $rectangles;
}

class AxTextStyle {
	var $font;
	var $fontSize;
	var $wordSpacing;
	var $characterSpacing;
	var $lineSpacing;
	var $decorations;
	var $color;
	var $transform;
}

class AxGraphicBox {
	var $left;
	var $bottom;
	var $right;
	var $top;
	var $style;	
	var $horizontalDividers;
	var $verticalDividers;
}

class AxGraphicLine {
	var $coordinates;
	var $style;
}

class AxExternalGrahpic {
	var $left;
	var $bottom;
	var $stream;
}

class AxFormField {
	var $type;
	var $flags;
	var $container;
	var $index;
	var $PDFStructure;
}

function ax_name($name) {
	return "\x1B$name";
}

function ax_rgb($r, $g, $b) {
	return $r << 16 | $g << 8 | $b;
}

function ax_grayscale($level) {
	return (float) ($level / 100);
}

function ax_date($utime) {
	$date = date('YmdHis', $utime);
	return "D:$date";
}

function ax_indirect_dictionary($name = null) {
	$array = array('__gen__' => -1);
	$array['Type'] = ($name) ? ax_name($name) : null;
	return $array;
}

function ax_create_document() {
	/* create basic PDF document structure */
	$root = ax_indirect_dictionary('Root');
	$root['Pages'] = ax_indirect_dictionary('Pages');
	$info = ax_indirect_dictionary();
	$info['Creator'] = AX_ACROSTIX_CREATOR_STRING;
	$info['CreationDate'] = ax_date(time());
	$trailer = array();
	$trailer['Root'] =& $root;
	$trailer['Info'] =& $info;	
	
	$doc_obj = new AxDocument;
	$doc_obj->pages = array();
	$doc_obj->info =& $info;
	$doc_obj->PDFStructure = $trailer;	
	return $doc_obj;
}

function ax_create_page($width, $height) {
	$width *= 72.0;
	$height *= 72.0;

	$page_obj = new AxPage;
	$page_obj->elements = array();
	$page_obj->width = $width;
	$page_obj->height = $height;
	$page_obj->mediaBox = array(0.0, 0.0, $width, $height);
	$page_obj->PDFStructure = ax_indirect_dictionary('Page');
	return $page_obj;
}

function ax_clone_page($page) {
	ax_start_logging();
	if(!is_a($page, 'AxPage')) {
		ax_end_logging("argument 1 should be an AxPage");
		return false;
	}
	if(AX_IN_PHP5) {
		$clone = clone($page);
	}
	else {
		$clone = $page;
	}
	/* clone the PDF structure too */
	unset($clone->PDFStructure);
	$clone->PDFStructure = $page->PDFStructure;
	/* break the share on the resource dictionary */
	$clone->resources = $page->resources;
	$clone->PDFStructure['Resources'] =& $clone->resources;
	$clone->PDFStructure['Resources']['Type'] = null;
	$clone->PDFStructure['__gen__'] = -1;
	ax_end_logging();
	return $clone;
}

function ax_create_stream($data) {
	$stream = new AxStream;
	$len = floatval(strlen($data));
	$stream->dictionary = array('Length' => $len, '__gen__' => -1);
	$stream->data = $data;
	return $stream;
}

function ax_compress_stream($stream) {
	static $has_gz;
	if(!isset($has_gz)) {
		$has_gz = function_exists('gzdeflate');		
	}
	if(!$has_gz) {
		return false;
	}
	$deflated_data = gzdeflate($stream->data);
  	$len = (float) strlen($deflated_data) + 2;
	$fstream = new AxStream;
	$fstream->dictionary = array_merge(array('Filter' => "\x1BFlateDecode", 'Length' => $len), $stream->dictionary);
	$fstream->data = "\x48\x89" . $deflated_data;
	return $fstream;
}

function ax_get_space_width($font, $scale_factor, $character_spacing, $word_spacing) {
	$width = @$font->widths[' '];
	$width *= $scale_factor * $font->scaleFactor;
	return $width + $character_spacing + $word_spacing;
}

function ax_get_text_metrics($s, $font, $scale_factor, $character_spacing) {
	$kerning_pairs = $font->kerningPairs;
	$widths = $font->widths;
	$len = strlen($s);
	$width = 0;
	if($kerning_pairs) {
		$kernings = array();
		for($i = 0; $i < $len; $i++) {
			$pair = substr($s, $i, 2);
			$width += @$widths[$pair[0]];
			if($adj = @$kerning_pairs[$pair]) {
				$kernings[$i + 1] = $adj;
				$width += $adj;
			}
		}		
	}
	else {
		$kernings = null;
		for($i = 0; $i < $len; $i++) {
			$width += $widths[$s[$i]];
		}
	}
	$width *= $scale_factor * $font->scaleFactor;
	$width += $len * $character_spacing;
	return array($width, $kernings);
}

function ax_create_rectangular_region($left, $bottom, $right, $top) {
	$rect = array($left * 72, $bottom * 72, $right * 72, $top * 72);
	$region = new AxRegion;
	$region->rectangles = array($rect);
	return $region;
}

function ax_create_multicolumn_region($left, $bottom, $right, $top, $columns, $gutter) {
	$width = $right - $left;
	$gutter_width = $gutter * ($columns - 1);
	$column_width = ($width - $gutter_width) / $columns;

	$rects = array();
	for($i = 0; $i < $columns; $i++) {
		$c_left = $left + ($column_width + $gutter) * $i;
		$c_right = $c_left + $column_width;
		$rects[] = array($c_left * 72, $bottom * 72, $c_right * 72, $top * 72);
	}

	$region = new AxRegion;
	$region->rectangles = $rects;
	return $region;
}

function ax_add_page_element(&$page, $element) {
	ax_start_logging();
	if(!is_a($page, 'AxPage')) {
		ax_end_logging("argument 1 should be an AxPage");
		return false;
	}
	if(!is_object($element)) {
		ax_end_logging("argument 2 should be an object");
		return false;
	}
	$page->elements[] = $element;	
	ax_end_logging();
	return 1;
}

function ax_add_page_elements(&$page, $elements) {
	ax_start_logging();
	$count = 0;
	if(!is_a($page, 'AxPage')) {
		ax_end_logging("argument 1 should be an AxPage");
		return false;
	}
	if(!is_array($elements)) {
		ax_end_logging("argument 2 must be an array");
		return false;
	}
	foreach($elements as $element) {
		if(is_array($element)) {
			$count += ax_add_page_elements($page, $element);
		}
		else {
			$page->elements[] = $element;
		}
	}
	ax_end_logging();
	return $count;
}

function ax_search_object(&$obj, &$array) {
	if(AX_IN_PHP5) {
		return array_search($obj, $array, true);
	}
	else {
		foreach($array as $key => $obj2) {
			if($obj2 === $obj) {
				return $key;
			}
		}
	}
	return false;	
}

function ax_create_image_stream($data, $width, $height, $color_space) {
	$stream = ax_create_stream($data);	
	$stream->dictionary['Filter'] = ax_name('DCTDecode');
	$stream->dictionary['Type'] = ax_name('XObject');
	$stream->dictionary['Subtype'] = ax_name('Image');
	$stream->dictionary['Width'] = (float) $width;
	$stream->dictionary['Height'] = (float) $height;
	$stream->dictionary['ColorSpace'] = ax_name($color_space);
	$stream->dictionary['BitsPerComponent'] = 8.0;
	$stream->dictionary['Interpolate'] = true;
	return $stream;
}

function ax_load_jpeg_file($file_path, $left, $bottom, $dpi = 72) {
	ax_start_logging();
	if(!($contents = file_get_contents($file_path))) {
		ax_end_logging("cannot open $file_path");
	}
	$info = getimagesize($file_path);
	if(!$info || $info[2] != 2) {
		ax_end_logging("$file_path is not a valid JPEG file");
	}	
	$width = $info[0];
	$height = $info[1];
	$is_color = $info['channels'] == 3;
	$image = new AxImage;
	$image->left = $left * 72;
	$image->bottom = $bottom * 72;
	$image->width = $width * 72 / $dpi;
	$image->height = $height * 72 / $dpi;
	$image->stream = ax_create_image_stream($contents, $width, $height, $is_color ? 'DeviceRGB' : 'DeviceGray');
	ax_end_logging();
	return $image;
}

function ax_create_image_from_gd($image, $left, $bottom, $dpi = 72, $quality = 75) {
	ax_start_logging();
	ob_start();
	imagejpeg($image, '', $quality);
	$contents = ob_get_clean();
	if(!$contents) {
		ax_end_logging("cannot open obtain JPEG data");
	}
	$width = imagesx($image);
	$height = imagesx($image);
	$image = new AxImage;
	$image->left = $left * 72;
	$image->bottom = $bottom * 72;
	$image->width = $width * 72 / $dpi;
	$image->height = $height * 72 / $dpi;
	$image->stream = ax_create_image_stream($contents, $width, $height, 'DeviceRGB');
	ax_end_logging();
	return $image;
}

function ax_create_box($left, $bottom, $right, $top, $thickness = 0.5, $color = 0.0, $backgroundColor = null) {
	$box = new AxGraphicBox;
	$box->left = $left * 72;
	$box->bottom = $bottom * 72;
	$box->right = $right * 72;
	$box->top = $top * 72;
	$box->style = array('color' => $color, 'thickness' => $thickness, 'backgroundColor' => $backgroundColor);
	return $box;
}

function ax_create_line($x1, $y1, $x2, $y2, $thickness = 0.5, $color = 0.0) {
	$line = new AxGraphicLine;
	$line->coordinates = array($x1 * 72, $y1 * 72, $x2 * 72, $y2 * 72);
	$line->style = array('color' => $color, 'thickness' => $thickness);
	return $line;
}

function ax_get_grid_divider_positions($height, $rows) {
	$dividers = array();
	if(is_array($rows)) {
		$num = (int) count($rows);
		for($i = 0; $i < $num - 1; $i++) {
			$dividers[] = $height * $rows[$i];
		}
	}
	else {
		$num = (int) $rows;
		$row_height = $height / $num;
		for($i = 1; $i < $num; $i++) {
			$dividers[] = $row_height * $i;
		}
	}
	return $dividers;
}

function ax_create_grid($left, $bottom, $right, $top, $rows, $columns, $thickness = 0.5, $color = 0.0, $backgroundColor = null) {
	$box = new AxGraphicBox;
	$box->left = $left * 72;
	$box->bottom = $bottom * 72;
	$box->right = $right * 72;
	$box->top = $top * 72;
	
	$width = $box->right - $box->left;
	$height = $box->top - $box->bottom;
	$box->horizontalDividers = ax_get_grid_divider_positions($height, $rows);
	$box->verticalDividers = ax_get_grid_divider_positions($width, $columns);
	$box->style = array('color' => $color, 'thickness' => $thickness, 'backgroundColor' => $backgroundColor);
	return $box;
}
  
function ax_start_logging() {
	global $__ax_entry_function, $__ax_log_depth, $__ax_error_log, $__ax_original_error_reporting;
	if(!$__ax_log_depth) {
		$__ax_original_error_reporting = error_reporting(0);
		$__ax_error_log = array();		
		$backtrace = debug_backtrace();
		$self = array_shift($backtrace);
		$caller = array_shift($backtrace);
		$__ax_entry_function = $caller['function'];
	}
	$__ax_log_depth++;
}

function ax_log_error($message) {
	global $__ax_error_log;
	if(is_array($__ax_error_log)) {
		$__ax_error_log[] = $message;
	}
}

function ax_error_log() {
	return $__ax_error_log;
}

function ax_end_logging($final_message = false) {
	global $__ax_entry_function, $__ax_log_depth, $__ax_error_log, $__ax_original_error_reporting;
	if($final_message) {
		ax_log_error($final_message);
	}
	$__ax_log_depth--;
	if(!$__ax_log_depth) {
		error_reporting($__ax_original_error_reporting);
		if($__ax_error_log) {
			$errors = implode(" : ", $__ax_error_log);
			$msg = "$__ax_entry_function(): $errors";
			trigger_error($msg, E_USER_WARNING);
		}
		$__ax_entry_function = null;
	}
}

function ax_format_error_log($log) {
	return implode('; ', $log);
}

require('ax_layout.php');
require('ax_mapping.php');
require('ax_truetype_font.php');
require('ax_type1_font.php');
require('ax_reader.php');
require('ax_writer.php');
require('ax_form.php');

if(version_compare(PHP_VERSION, "4.3.0", "<")) {
	require('ax_compat_42.php');
}

?>