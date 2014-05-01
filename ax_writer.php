<?php

function ax_save_pdf_file($file_path, &$doc_obj) {
	ax_start_logging();
	$contents = ax_generate_pdf_contents($doc_obj);
	if(!$contents) {
		ax_end_logging("cannot generate PDF contents");
		return false;
	}
	if(!($f = fopen($file_path, 'wb'))) {
		ax_end_logging("cannot open $file_path");
		return false;
	}
	fwrite($f, $contents);
	fclose($f);
	ax_end_logging();
	return true;
}

function ax_output_pdf_file(&$doc_obj) {
	if($contents = ax_generate_pdf_contents($doc_obj)) {
		echo $contents;
		return true;
	}
	return false;
}

function ax_pack_document(&$doc_obj) {
	$root =& $doc_obj->PDFStructure['Root'];
	$info =& $doc_obj->PDFStructure['Info'];
	$parent = null;
	ax_pack_pages($doc_obj, 0, count($doc_obj->pages), $parent, $root['Pages']);
	
	/* set the modified date */
	$info['ModDate'] = ax_date(time());
	
	/* add producer string */
	if($info['Producer']) {
		$info['Producer'] .= ", reproduced by " . AX_ACROSTIX_CREATOR_STRING;
	}
	else {
		$info['Producer'] = AX_ACROSTIX_CREATOR_STRING;
	}
	
	/* when the document is recreated rights assigned to the 
	   original will no longer be valid; we remove the field
	   therefore to avoid the warning message */
	if(array_key_exists('ViewerPreferences', $root)) {
		$pref =& $root['ViewerPreferences'];
		unset($pref['Rights']);
	}
	return true;	
}

function ax_reference_elements(&$array, &$ref_table, $gen_num, $depth = 0) {
	while(($key = key($array)) !== null) {
		$value =& $array[$key];
		$type = gettype($value);		
		if($type == 'array') {
			/* the generation number is used to determine whether a reference
			   already exists for the object; it also serves as a marker that
			   it should be referenced indirectly; it starts at 1 and is 
			   incremented each time the document is saved;
			*/
			if($old_gen = $value['__gen__']) {
				if($gen_num != $old_gen) {
					$clone = $value;
					$ref_table[] =& $clone;
					$value['__ref__'] = $id = count($ref_table);
					$value['__gen__'] = $gen_num;
					ax_reference_elements($clone, $ref_table, $gen_num, $depth + 1);
					unset($clone);
				}
				else {
					$id = $value['__ref__'];
				}
				$array[$key] =& $id;
				unset($id);
			}
			else {
				$clone = $value;
				ax_reference_elements($clone, $ref_table, $gen_num, $depth + 1);
				$array[$key] =& $clone;				
				unset($clone);
			}
		}		
		else if($type == 'object') {
			if($old_gen = $value->dictionary['__gen__']) {
				if($gen_num != $old_gen) {
					/* clone the stream manually  */
					$clone = new AxStream;
					$clone->data = $value->data;
					$clone->dictionary = $value->dictionary;					
					$ref_table[] =& $clone;
					$value->dictionary['__ref__'] = $id = count($ref_table);
					$value->dictionary['__gen__'] = $gen_num;
					ax_reference_elements($clone->dictionary, $ref_table, $gen_num, $depth + 1);
					unset($clone);
				}
				else {
					$id = $value->dictionary['__ref__'];
				}
				$array[$key] =& $id;
				unset($id);
			}
			else {
				die('stream must be referenced indirectly');
			}
		}
		next($array);
	}
	reset($array);
}

function ax_generate_pdf_contents(&$doc_obj) {
	static $gen_num = 2;
	
	/* first, we need to pack the document into PDF data structures */
	ax_pack_document($doc_obj);
	
	/* now, everything is in PDF data structures; all objects are still
	   referenced directly, however; we need to place some of them in to 
	   an object table and make the PHP references PDF ones (i.e. indices 
	   into the object table)
	 */
	$trailer = $doc_obj->PDFStructure;
	$objects = array();
	ax_reference_elements($trailer, $objects, $gen_num++);
	$trailer['Size'] = (float) count($objects) + 1;

	/* start writing out the PDF contents */
	$contents = '%PDF-1.3';

	/* random noise to indicate that this is a binary file */
	$contents .= "\n\xC5\xE7\xAE\xF5\xA7\x74\xEC\xD7";

	/* serialize the objects, noting the byte-offset of each */
	$byte_offsets = array(0);
	foreach($objects as $index => $obj) {	
		$id = $index + 1;
		$byte_offsets[$id] = strlen($contents) + 1;
		$obj_data = ax_serialize_object($obj);
		$contents .= "\n$id 0 obj\n$obj_data\nendobj";
	}
	
	/* create the cross-reference table, remembering its byte-offset */
	$startxref = strlen($contents);	
	$contents .= ax_serialize_xref_and_trailer($byte_offsets, $trailer);
	
	/* add the offset to the cross-reference table and we're done! */
	$contents .=  "\nstartxref\n$startxref\n%%EOF";
	return $contents;
}

function ax_pack_pages(&$doc_obj, $offset, $count, &$parent, &$pages) {
	$pages = ax_indirect_dictionary('Pages');
	if($parent) {
		$pages['Parent'] =& $parent;
	}
	$kids = array();
	/* if there are more than 8 pages, then store them in 
	   separate sub-nodes */
	$limit = $offset + $count;
	if($count > 8) {
		$per_node = ceil($count / 8);
		for($i = 0, $j = $offset, $r = $count; $j < $limit; $i++, $j += $per_node, $r -= $per_node) {
			ax_pack_pages($doc_obj, $j, min($per_node, $r), $pages, $kids[$i]);
		}
		$pages['Count'] = (float) $count;
	}
	else {
		for($i = $offset; $i < $limit; $i++) {
			$page_obj =& $doc_obj->pages[$i];
			$page =& $page_obj->PDFStructure;
			$page['Parent'] =& $pages;
			if(is_float($page_obj->rotate)) {
				$page['Rotate'] = $page_obj->rotate;
			}
			if(is_array($page_obj->mediaBox)) {
				$page['MediaBox'] = $page_obj->mediaBox;
			}
			if(is_array($page_obj->cropBox)) {
				$page['CropBox'] = $page_obj->cropBox;
			}

			/* generate content streams and add resources */
			if(ax_generate_content_streams($page_obj, $streams, $resources)) {	
				$page['Contents'] =& $streams;
				$page['Resources'] =& $resources;
				unset($streams);
				unset($resources);
			}
			$kids[] =& $page;
		}
		$pages['Count'] = (float) count($kids);
	}
	$pages['Kids'] = $kids;
	return true;
}

function ax_add_style_operators($new_style, $cur_style, &$ops, &$embedded_fonts) {	
	if($cur_style) {
		$diff = array_diff_assoc($new_style, $cur_style);
		/* array_diff doesn't work with objects */
		if($cur_style['font'] !== $new_style['font']) {
			$diff['font'] = $new_style['font'];
		}
	}
	else {
	 	$diff = $new_style;
	}
	if($diff) {
		if($diff['font'] || $diff['fontSize'] || $diff['scale']) {
			$size = $new_style['fontSize'];
			$font = $new_style['font'];
			$scale = $new_style['scale'];
			$actual_size = $size * $scale;
			$name = ax_search_object($font->PDFStructure, $embedded_fonts);
			if(!$name) {
				$i = 0;
				do {
					$name = "F$i";
					$i++;
				} while(array_key_exists($name, $embedded_fonts));				
				$embedded_fonts[$name] =& $font->PDFStructure;
			}
			$ops .= "/$name $actual_size Tf ";
		}
		if($ratio = $diff['aspectRatio']) {
			$hscale = $ratio * 100;
			$ops .= "$hscale Tz ";
		}
		if(!is_null($trans = $diff['yTranslation'])) {
			$rise = $new_style['fontSize'] * $trans;
			$ops .= "$rise Ts ";
		}
		if(!is_null($ws = $diff['wordSpacing'])) {
			$ws = round($ws, 4);
			$ops .= "$ws Tw ";
		}
		if(!is_null($cs = $diff['characterSpacing'])) {
			$cs = round($cs, 4);
			$ops .= "$cs Tc ";
		}
		if(!is_null($color = $diff['color'])) {
			if(is_float($color)) {
				/* grayscale */
				$ops .= "$color g ";
			}
			else { 
				/* r g b */
				$r = ($color & 0x00FF0000) >> 16;
				$g = ($color & 0x0000FF00) >> 8; 
				$b = ($color & 0x000000FF);
				$ops .= "$r $g $b rg ";
			}
		}
		return true;
	}
	return false;
}

function ax_add_decorations($style, $left, $bottom, $width, $height, &$ops) {
	$flags = $style['decorations'];
	$ops .= "q ";

	/* set stroke color */
	$color = $style['color'];
	if(is_float($color)) {
		/* grayscale */
		$ops .= "$color G ";
	}
	else {
		/* r g b */
		$r = ($color & 0x00FF0000) >> 16;
		$g = ($color & 0x0000FF00) >> 8; 
		$b = ($color & 0x000000FF);
		$ops .= "$r $g $b RG ";
	}

	$font = $style['font'];
	$scaling = $font->scaleFactor * $style['fontSize'];

	if($flags & AX_UNDERLINE) {			
		/* set line thickness */
		$thickness = $font->attributes['underlineThickness'] * $scaling;
		$ops .= "$thickness w ";
	
		$offset = $font->attributes['underlinePosition'] * $scaling;

		/* draw line */	
		$x1 = $left;
		$y = $bottom + $offset;
		$x2 = $x1 + $width;
		$ops .= "$x1 $y m $x2 $y l S ";
	}
	if($flags & AX_DOUBLE_UNDERLINE) {
		/* set line thickness */
		$thickness = $font->attributes['underlineThickness'] * $scaling;
		$ops .= "$thickness w ";

		/* draw lines */	
		$offset = $font->attributes['underlinePosition'] * $scaling;
		$x1 = $left;
		$y1 = $bottom + $offset;
		$x2 = $x1 + $width;
		$y2 = $y1 + $offset;
		$ops .= "$x1 $y1 m $x2 $y1 l $x1 $y2 m $x2 $y2 l S ";
	}
	if($flags & AX_LINE_THROUGH) {
		/* set line thickness */
		$thickness = $font->attributes['strikeoutThickness'] * $scaling;
		$ops .= "$thickness w ";
	
		/* draw lines */	
		$offset = $font->attributes['strikeoutPosition'] * $scaling + $font->rise;
		$x1 = $left;
		$y = $bottom + $offset;
		$x2 = $x1 + $width;
		$ops .= "$x1 $y m $x2 $y l S ";
	}
	$ops .= "Q ";
}

function ax_add_graphic_states($style, &$ops) {
	if(!is_null($thickness = $style['thickness'])) {
		$ops .= "$thickness w ";
	}
	if(!is_null($joint_style = $style['joint'])) {
		$ops .= "$joint_style j ";
	}
	if(!is_null($dash = $style['dash'])) {
		$ops .= "$dash d ";
	}
	if(!is_null($color = $style['color'])) {
		if(is_float($color)) {
			/* grayscale */
			$ops .= "$color G ";
		}
		else { 
			/* r g b */
			$r = ($color & 0x00FF0000) >> 16;
			$g = ($color & 0x0000FF00) >> 8; 
			$b = ($color & 0x000000FF);
			$ops .= "$r $g $b RG ";
		}
		$operator = 's';
	}	
	if(!is_null($color = $style['backgroundColor'])) {
		if(is_float($color)) {
			/* grayscale */
			$ops .= "$color g ";
		}
		else { 
			/* r g b */
			$r = ($color & 0x00FF0000) >> 16;
			$g = ($color & 0x0000FF00) >> 8; 
			$b = ($color & 0x000000FF);
			$ops .= "$r $g $b rg ";
		}
	}
	$ops .= "";
}

function ax_add_graphic_draw_operator($style, &$ops) {
	if(!is_null($style['color'])) {
		if(!is_null($style['backgroundColor'])) {
			$ops .= "B ";
		}
		else {
			$ops .= "S ";
		}
	}
	else if(!is_null($style['backgroundColor'])) {
		$ops .= "f ";
	}
}

function ax_generate_content_streams(&$page_obj, &$streams, &$resources) {
	if($page_obj->elements) {
		$resources = $page_obj->resources;
		$embedded_fonts = $resources['Font'];
		if(!is_array($embedded_fonts)) {
			$embedded_fonts = array();
		}
		$embedded_xobjects = $resources['XObject'];
		if(!is_array($embedded_xobjects)) {
			$embedded_xobjects = array();
		}
		$ops = '1 0 0 1 0 0 cm ';

		/* initial text state */
		$cur_style = array('color' => 0, 'scale' => 1, 'aspectRatio' => 1, 
						   'verticalOffset' => 0, 'wordSpacing' => 0, 'characterSpacing' => 0);
		$cursor_x = 0;
		$cursor_y = 0;
		foreach($page_obj->elements as $element) {
			if(is_a($element, 'AxText')) {
				$ops .= "BT ";

				/* add style operators as necessary */
				ax_add_style_operators($element->style, $cur_style, $ops, $embedded_fonts);

				/* add position operator */
				$x = round($element->left, 4);
				$y = round($element->bottom, 4);
				$ops .= "$x $y Td ";

				/* add text showing operator */
				if($element->kernings) {
					$a = array();
					$last_index = 0;
					foreach($element->kernings as $pos => $amount) {
						$a[] = substr($element->text, $last_index, $pos - $last_index);
						$a[] = - (float) $amount;
						$last_index = $pos;
					}
					$a[] = substr($element->text, $last_index);
					$s = ax_serialize_array($a);
					$ops .= "$s TJ ";
				}
				else {
					$s = ax_serialize_string($element->text);
					$ops .= "$s Tj ";
				}
				$ops .= "ET ";

				if($element->style['decorations']) {
					ax_add_decorations($element->style, $element->left, $element->bottom, $element->width, $element->height, $ops);
				}
				$cur_style = $element->style;
			}
			else if(is_a($element, 'AxImage')) {
				$xobject =& $element->stream;
				$name = ax_search_object($xobject, $embedded_xobjects);
				if(!$name) {
					$i = 0;
					do {
						$name = "Im$i";
						$i++;
					} while(array_key_exists($name, $embedded_xobjects));
					$embedded_xobjects[$name] =& $xobject;
				}
				$x = $element->left;
				$y = $element->bottom;
				$w = $element->width;
				$h = $element->height;
				$ops .= "q $w 0 0 $h $x $y cm /$name Do Q ";
			}
			else if(is_a($element, 'AxExternalGrahpic')) {
				$xform =& $element->stream;
				$name = ax_search_object($xform, $embedded_xobjects);
				if(!$name) {
					$i = 0;
					do {
						$name = "XF$i";
						$i++;
					} while(array_key_exists($name, $embedded_xobjects));
					$embedded_xobjects[$name] =& $xform;
				}
				$x = $element->left;
				$y = $element->bottom;
				$ops .= "q 1 0 0 1 $x $y cm /$name Do Q ";
			}
			else if(is_a($element, 'AxGraphicLine')) {
				$ops .= "q ";
				ax_add_graphic_states($element->style, $ops);				
				$coord = $element->coordinates;
				for($i = 0; $i < count($coord); $i += 2) {
					$x = $coord[$i];
					$y = $coord[$i + 1];
					$op = ($i == 0) ? 'm' : 'l';
					$ops .= "$x $y $op ";					
				}
				ax_add_graphic_draw_operator($element->style, $ops);
				$ops .= "Q ";
			}
			else if(is_a($element, 'AxGraphicBox')) {
				$ops .= "q ";
				ax_add_graphic_states($element->style, $ops);
				$x1 = $element->left;
				$y1 = $element->bottom;
				$x2 = $element->right;
				$y2 = $element->top;
				$ops .= "$x1 $y1 m $x1 $y2 l $x2 $y2 l $x2 $y1 l h ";
				ax_add_graphic_draw_operator($element->style, $ops);
				if($element->verticalDividers) {
					foreach($element->verticalDividers as $h) {				
						$x = $x1 + $h;
						$ops .= "$x $y1 m $x $y2 l S ";
					}
				}
				if($element->horizontalDividers) {
					foreach($element->horizontalDividers as $v) {
						$y = $y1 + $v;
						$ops .= "$x1 $y m $x2 $y l S ";
					}
				}
				$ops .= "Q ";
			}
		}
		$new_stream = ax_create_stream($ops);
		/* compress the stream if possible */
		if($fstream = ax_compress_stream($new_stream)) {
			$new_stream = $fstream;
		}
		if($page_obj->contents) {
			if(is_array($page_obj->contents)) {
				$streams = $page_obj->contents;
			}
			else {
				$streams = array();
				if($page_obj->contents) {
					$streams[] =& $page_obj->contents;
				}
			}		
			$streams[] = $new_stream;
		}
		else {
			$streams = $new_stream;
		}
		
		if(count($embedded_fonts)) {
			$embedded_fonts['Type'] = null;
		}
		else {
			unset($resources['Font']);
		}
		if(count($embedded_xobjects)) {
			$embedded_xobjects['Type'] = null;
		}
		else {
			unset($resources['XObject']);
		}
		$resources['Font'] = $embedded_fonts;
		$resources['XObject'] = $embedded_xobjects;
		$resources['Type'] = null;
		return true;
	}
	return false;
}

function ax_serialize_object($obj) {	
	if(is_int($obj)) {
		return ax_serialize_reference($obj);
	}
	if(is_float($obj)) {
		return ax_serialize_number($obj);
	}
	if(is_string($obj)) {
		if(ord($obj) == 0x1B) {
			return ax_serialize_name($obj);
		}
		else {
			return ax_serialize_string($obj);
		}
	}
	if(is_object($obj) && is_a($obj, 'AxStream')) {
		return ax_serialize_stream($obj);
	}
	if(is_array($obj)) {
		if(array_key_exists('Type', $obj)) {
			return ax_serialize_dictionary($obj);
		}
		else {
			return ax_serialize_array($obj);
		}
	}
	if(is_null($obj)) {
		return ax_serialize_null($obj);
	}
	if(is_bool($obj)) {
		return ax_serialize_boolean($obj);
	}
}

function ax_serialize_reference($obj) {
	$obj_id = ($obj & 0x00FFFFFF);
	$gen_num = $obj >> 24;
	return "$obj_id $gen_num R";
}

function ax_serialize_number($obj) {
	return (string) $obj;
}

function ax_serialize_string($obj) {
	static $escape_table;

	if(false && preg_match('/[^\x20-\x7F]/', $obj)) {
		$len = strlen($obj);	
		$a = unpack("H$len", $obj);
		$hex = $a[1];
		return "<$hex>";
	}
	else {
		if(!$escape_table) {
			$escape_table = array('(' => '\\(', ')' => '\\)', '\\' => '\\\\', "\n" => '\n', "\r" => '\r', "\t" => '\t');
		}
		$s = strtr($obj, $escape_table);
		return "($s)";
	}
}

function ax_serialize_name($obj, $escaped = true) {
	$name = ($escaped) ? substr($obj, 1) : $obj;
	return "/$name";
}

function ax_serialize_stream($obj) {
	$dict_data = ax_serialize_dictionary($obj->dictionary);
	$stream_data = $obj->data;
	return "$dict_data\nstream\n{$stream_data}endstream";
}

function ax_serialize_dictionary($obj) {
	$items = array();
	foreach($obj as $name => $val) {
		if($name !== '__ref__' && $name !== '__gen__') {
			if(!is_null($val) || $name != 'Type') {
				$items[] = ax_serialize_name($name, false);
				$items[] = ax_serialize_object($val);
			}
		}
	}
	$contents = implode(' ', $items);
	return "<<$contents>>";
}

function ax_serialize_array($obj) {
	$items = array();
	foreach($obj as $index => $val) {
		if($index !== '__ref__' && $index !== '__gen__') {
			$items[] = ax_serialize_object($val);
		}
	}
	$contents = implode(' ', $items);
	return "[$contents]";
}

function ax_serialize_null($obj) {
	return 'null';
}

function ax_serialize_boolean($obj) {
	return $obj ? 'true' : 'false';
}

function ax_serialize_xref_and_trailer($byte_offsets, $trailer) {
	$size = count($byte_offsets);
	$records = array();
	foreach($byte_offsets as $offset) {
		if($offset) {
			$records[] = sprintf("%010d %05d n ", $offset, 0);
		}
		else {
			$records[] = sprintf("%010d %05d f ", 0, 65535);
		}
	}
	$trailer_data = ax_serialize_dictionary($trailer);
	$record_data = implode("\n", $records);
	return "\nxref\n0 $size\n$record_data\ntrailer\n$trailer_data";
}

?>