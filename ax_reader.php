<?php

function ax_open_pdf_file($file_path) {
	ax_start_logging();
	if(!$data = file_get_contents($file_path)) {
		ax_end_logging("cannot open $file_path");
		return false;
	}
	$doc = ax_parse_pdf_contents($data);
	ax_end_logging();
	return $doc;
}

function ax_parse_pdf_contents($data) {
	ax_start_logging();

	/* look for PDF signature */
	if(!preg_match('/^(.*?)%PDF-(.*?)[\r\n]/', $data, $m)) {
		ax_log_error("cannot find file header");
		return false;
	}
	$junk = $m[1];
	$pdf_version = $m[2];

	/* skip any junk at beginning of file */
	if($junk) {
		$data = substr($data, strlen($junk));
	}
	$len = strlen($data);

	/* find the location of the cross-reference sections */
	if(!preg_match('/startxref(?:\n|\r|\r\n)(\d+)/', substr($data, $len - 128), $m)) {
		ax_end_logging("cannot find byte offset to cross-reference table");
		return false;
	}
	$startxref = intval($m[1]);

	/* parse the xref table and trailer */
	list($byte_offsets, $trailer) = ax_parse_xref_and_trailer($data, $startxref);
	if(!$byte_offsets && !$trailer) {
		ax_end_logging("cannot parse cross-reference table");
		return false;
	}
	if(!$trailer) {
		ax_end_logging("cannot parse file trailer");
		return false;
	}
	$xref_offsets = array($startxref);

	$prev_startxref = $trailer['Prev'];
	while($prev_startxref) {
		/* get the xref table of a linearized PDF file */
		list($prev_byte_offsets, $prev_trailer) = ax_parse_xref_and_trailer($data, $prev_startxref);

		foreach($prev_byte_offsets as $id => $offset) {
			$byte_offsets[$id] = $offset;
		}
		$xref_offsets[] = $prev_startxref;
		$prev_startxref = $prev_trailer['Prev'];
	}

	/* can't open the file if it's encrypted */
	if($trailer['Encrypt']) {
		ax_end_logging("cannot open encrypted file");
		return false;
	}

	/* sort the offsets, then figure out the end offset of each object */
	$start_offsets = array_values($byte_offsets);
	foreach($xref_offsets as $offset) {
		$start_offsets[] = $offset;
	}
	sort($start_offsets);
	$end_offsets = array();
	$prev_offset = 0;
	foreach($start_offsets as $offset) {		
		$end_offsets[$prev_offset] = $offset;
		$prev_offset = $offset;
	}

	/* parse the objects */
	$objects = array();
	foreach($byte_offsets as $id => $offset) {
		$obj_start_offset = strpos($data, 'obj', $offset) + 3;
		$obj_end_offset = strpos($data, 'endobj', $end_offsets[$offset] - 11);

		$obj_data = substr($data, $obj_start_offset, $obj_end_offset - $obj_start_offset);
		$s = $obj_data;
		$obj = ax_parse_object($obj_data, $id);
		if($obj !== 0) {
			$objects[$id] = $obj;
		}
		else {
			ax_end_logging("cannot parse object #$id");
		}
	}
	unset($trailer['Prev']);

	/* resolve all references */
	$ref_table = array();
	ax_resolve_array($trailer, $objects, $ref_table);
	
	if(!ax_unpack_document($trailer, $doc_obj)) {
		return false;
	}
	
	ax_end_logging();
	return $doc_obj;
}

function ax_unpack_document(&$tree, &$doc_obj) {
	$doc_obj = new AxDocument;
	$doc_obj->PDFStructure =& $tree;
	$root =& $tree['Root'];
	if(!$root) {
		ax_log_error("cannot find page catalog");		
		return false;
	}
	if(!ax_unpack_pages($root['Pages'], array(), $doc_obj)) {
		return false;
	}
	if(array_key_exists('AcroForm', $root)) {
		if(!ax_unpack_form($root['AcroForm'], $doc_obj)) {
			return false;
		}
	}
	return $doc_obj;
}

function ax_merge_resources($resources1, $resources2) {
	$combined = array();
	foreach($resources2 as $name => $list2) {
		if(is_array($list2)) {
			$list1 = $resources1[$name];
			if($list1) {
				$combined[$name] = array_merge($list1, $list2);
			}
			else {
				$combined[$name] = $list2;
			}
		}
	}
	return $combined;
}

function ax_unpack_pages(&$pages, $inherited_properties, &$doc_obj) {
	$properties = $inherited_properties;
	if($mediabox = $pages['MediaBox']) {
		$properties['MediaBox'] = $mediabox;
	}
	if($cropbox = $pages['CropBox']) {
		$properties['CropBox'] = $cropbox;
	}
	if(is_float($rotate = $pages['Rotate'])) {
		$properties['Rotate'] = $rotate;
	}
	if($resources = $pages['Resources']) {
		$properties['Resources'] = ax_merge_resources((array) $properties['Resources'], $resources);
	}
	$kids =& $pages['Kids'];
	for($i = 0; $i < count($kids); $i++) {
		$kid =& $kids[$i];
		switch($kid['Type']) {
			case "\x1BPages":
				if(!ax_unpack_pages($kid, $properties, $doc_obj)) {
					ax_log_error("cannot unpack pages");
					return false;
				}
			break;
			case "\x1BPage":
				if(!ax_unpack_page($kid, $properties, $doc_obj)) {
					$num = count($doc_obj->pages) + 1;
					ax_log_error("cannot unpack page $num");
					return false;
				}
			break;
		}
	}	
	return true;
}

function ax_unpack_page(&$page, $inherited_properties, &$doc_obj) {
	if($resources = $page['Resources']) {
		$resources = ax_merge_resources($inherited_properties['Resources'], $resources);
	}
	else {
		$resources = $inherited_properties['Resources'];
	}
	if(!($mediabox = $page['MediaBox'])) {
		$mediabox = $inherited_properties['MediaBox'];
	}
	if(!($cropbox = $page['CropBox'])) {
		$cropbox = $inherited_properties['CropBox'];
	}
	if(!is_float($rotate = $page['Rotate'])) {
		$rotate = $inherited_properties['Rotate'];
	}
	
	$page_obj = new AxPage;
	$page_obj->elements = array();
	$page_obj->mediaBox = $mediabox;	
	$page_obj->cropBox = $cropbox;	
	$page_obj->rotate = $rotate;
	$page_obj->width = abs($mediabox[2] - $mediabox[0]);
	$page_obj->height = abs($mediabox[3] - $mediabox[1]);
	$page_obj->resources =& $resources;
	$page_obj->contents =& $page['Contents'];
	$page_obj->PDFStructure =& $page;
	$doc_obj->pages[] = $page_obj;
	return true;
}

function ax_find_parent_page(&$pages, &$page, &$field_obj) {
	$ref = $page['__ref__'];
	for($i = 0; $i < count($pages); $i++) {
		if($pages[$i]->PDFStructure['__ref__'] == $ref) {
			$field_obj->container =& $pages[$i];
			return true;
		}
	}
	return false;
}

function ax_find_parent_pages(&$pages, &$kids, &$field_obj) {
	$refs = array();
	$containers = array();
	for($i = 0; $i < count($pages); $i++) {
		$refs[$i] = $pages[$i]->PDFStructure['__ref__'];
	}
	for($i = 0; $i < count($kids); $i++) {
		$page_index = array_search($kids[$i]['P']['__ref__'], $refs);
		$containers[$i] =& $pages[$page_index];
	}
	$field_obj->container = $containers;
	return count($containers) == count($kids);
}

function ax_unpack_form(&$form, &$doc_obj) {
	$fields =& $form['Fields'];
	$doc_obj->formFields = array();
	for($i = 0; $i < count($fields); $i++) {
		$field =& $fields[$i];		
		$field_obj = new AxFormField;
		$field_obj->type = substr($field['FT'], 1);
		$field_obj->flags = $field['Ff'];
		$field_obj->index = $i;
		$name = $field['T'];
		if(preg_match("/^\xFE\xFF/", $name)) {
			$name = str_replace("\x00", "", substr($name, 2));
		}
		if(array_key_exists('P', $field)) {
			ax_find_parent_page($doc_obj->pages, $field['P'], $field_obj);
		}
		else if(array_key_exists('Kids', $field)) {
			ax_find_parent_pages($doc_obj->pages, $field['Kids'], $field_obj);
		}
		$field_obj->PDFStructure =& $field;
		$doc_obj->formFields[$name][] = $field_obj;
	}
	return true;
}

function ax_resolve_array(&$array, &$objects, &$ref_table, $depth = 0) {	
	while(($key = key($array)) !== null) {
		$value =& $array[$key];
		$type = gettype($value);
		if($type == 'integer' && $key !== '__gen__' && $key !== '__ref__') {
			$obj =& $objects[$value];
			$type = gettype($obj);
			if($type == 'array') {
				if(!$ref_table[$value]) {
					$ref_table[$value] = true;
					ax_resolve_array($obj, $objects, $ref_table, $depth + 1);
				}
			}
			else if($type == 'object') {
				if(!$ref_table[$value]) {
					$ref_table[$value] = true;
					ax_resolve_array($obj->dictionary, $objects, $ref_table, $depth + 1);
				}
			}
			$array[$key] =& $obj;
		}
		else if($type == 'array') {
			ax_resolve_array($value, $objects, $ref_table, $depth + 1);
		}
		else if($type == 'object') {
			ax_resolve_array($value->dictionary, $objects, $ref_table, $depth + 1);
		}
		next($array);
	}
	reset($array);
}

function ax_parse_xref_and_trailer($s, $startxref) {	
	if(substr($s, $startxref, 4) == 'xref') {
		$i = $startxref + 5;
		$byte_offsets = array();
		while(preg_match('/\s*(\d+) (\d+)\s+/', substr($s, $i, 16), $m)) {
			$obj_num_offset = intval($m[1]);
			$rec_count = intval($m[2]);
			$i += strlen($m[0]);
			for($j = 0; $j < $rec_count; $j++) {
				$record = substr($s, $i, 20);
				if(sscanf($record, "%d %d %c", $offset, $gen_num, $flag) == 3) {	
					if($flag == 'n') {
						/* 8 bits for generation number, 24 for object number */
						$id = ($gen_num << 24) | $obj_num_offset + $j;  
						$byte_offsets[$id] = $offset;
					}
					$i += 20;
				}
				else {
					$byte_offsets = null;
					break;
				}
			}
		}
		$trailer_dict = null;
		if(preg_match('/trailer(?:\n|\r|\r\n)(<<.*?>>)/s', substr($s, $i, 256), $m)) {
			$s = $m[1];
			$trailer_dict = ax_parse_dictionary($s, 0);
		}
		return array($byte_offsets, $trailer_dict);
	}
	return array(null, null);
}

function ax_parse_dictionary(&$s, $ref) {
	if(preg_match('/^\s*<</', $s, $m)) {
		$s = substr($s, strlen($m[0]));
		$dict = array();
		while(!preg_match('/^\s*>>/', $s, $m)) {
			$name = ax_parse_name($s, false);
			if($name === 'JS') {
				return $dict;
			}
			$value = ax_parse_object($s);
			if($name !== 0 && $value !== 0) {
				$dict[$name] = $value;
			}
			else {
				return 0;
			}
		}
		$s = substr($s, strlen($m[0]));
		if(!array_key_exists('Type', $dict)) {
			$dict['Type'] = null;
		}
		if($ref) {
			$dict['__ref__'] = $ref;
			$dict['__gen__'] = 1;
		}
		return $dict;
	}
	return 0;
}

function ax_parse_array(&$s, $ref) {
	if(preg_match('/^\s*\[/', $s, $m)) {
		$s = substr($s, strlen($m[0]));
		$array = array();
		while(!preg_match('/^\s*\]/', $s, $m)) {
			$value = ax_parse_object($s);
			if($value !== 0) {
				$array[] = $value;
			}
			else {
				return 0;
			}
		}
		$s = substr($s, strlen($m[0]));
		if($ref) {
			$array['__ref__'] = $ref;
			$array['__gen__'] = 1;
		}
		return $array;
	}
	return 0;
}

function ax_parse_name(&$s, $escape = true) {
	if(preg_match('/^\s*\/([^\s\[\]\(\)\/<>]+)/', $s, $m)) {
		$s = substr($s, strlen($m[0]));
		$name = ($escape) ? "\x1B" . $m[1] : $m[1];
		return $name;
	}
	return 0;
}

function ax_parse_reference(&$s) {
	if(preg_match('/^\s*(\d+)\s+(\d+)\s+R/', $s, $m)) {
		$s = substr($s, strlen($m[0]));
		$obj_num = intval($m[1]);
		$gen_num = intval($m[2]);
		$id = ($gen_num << 24) | $obj_num;
		return $id;
	}
	return 0;
}

function ax_parse_number(&$s) {
	if(preg_match('/^\s*([+-\.0-9]+)/', $s, $m)) {
		$s = substr($s, strlen($m[0]));
		$number = floatval($m[1]);
		return $number;
	}
	return 0;
}

function ax_parse_string(&$s) {
	if(preg_match('/^\s*\(/', $s, $m)) {
		/* too hard to handle escape sequences with regexp,
		   parsing it manually instead */
		$offset = strlen($m[0]);
		$index = $offset;
		do {
			$close_para_pos = strpos($s, ')', $index);
			if($close_para_pos) {
				do {
					$slash_pos = strpos($s, '\\', $index);
					if($slash_pos == $close_para_pos - 1) {
						$index = $close_para_pos + 1;
						$close_para_pos = false;
						break;
					}
					$index = $slash_pos + 1;
				} while($slash_pos);
			}
		} while(!$close_para_pos);		
		$len = $close_para_pos - $offset;
		$string = substr($s, $offset, $len);	
		$string = stripcslashes($string);	
		$s = substr($s, $close_para_pos + 1);
		return $string;
	}
	else if(preg_match('/^\s*<([0-9a-f]*)>/i', $s, $m)) {
		$s = substr($s, strlen($m[0]));
		$string = pack("H*", $m[1]);
		return $string;
	}
	return 0;
}

function ax_parse_boolean(&$s) {
	if(preg_match('/^\s*true/', $s, $m)) {
		$s = substr($s, strlen($m[0]));
		return true;
	}
	else if(preg_match('/^\s*false/', $s, $m)) {
		$s = substr($s, strlen($m[0]));
		return false;
	}
	return 0;
}

function ax_parse_null(&$s) {
	if(preg_match('/^\s*null/', $s, $m)) {
		$s = substr($s, strlen($m[0]));
		return null;
	}
	return 0;
}

function ax_parse_stream(&$s, $ref = 0) {
	if(preg_match('/endstream\s*$/', $s, $m1)) {
		if(preg_match('/^\s*(<<.*?>>)\s*stream(?:\n|\r\n)/s', $s, $m2)) {		
			$offset = strlen($m2[0]);
			$len = strlen($s) - $offset - strlen($m1[0]);
			$data = substr($s, $offset, $len);
			$s = '';
			$dict_data = $m2[1];
			$dict = ax_parse_dictionary($dict_data, $ref);
			if($dict && $data) {
				$stream = new AxStream;
				$stream->dictionary = $dict;
				$stream->data = $data;
				return $stream;
			}
		}
	}
	return 0;
}

function ax_parse_object(&$s, $ref = 0) {
	if(($obj = ax_parse_reference($s)) !== 0) {
		return $obj;
	}
	if(($obj = ax_parse_number($s)) !== 0) {
		return $obj;
	}
	if(($obj = ax_parse_name($s)) !== 0) {
		return $obj;
	}
	if(($obj = ax_parse_string($s)) !== 0) {
		return $obj;
	}
	if(($obj = ax_parse_stream($s, $ref)) !== 0) {
		return $obj;
	}
	if(($obj = ax_parse_array($s, $ref)) !== 0) {
		return $obj;
	}
	if(($obj = ax_parse_dictionary($s, $ref)) !== 0) {
		return $obj;
	}
	if(($obj = ax_parse_null($s)) !== 0) {
		return $obj;
	}
	if(($obj = ax_parse_boolean($s)) !== 0) {
		return $obj;
	}
	return 0;
}

?>