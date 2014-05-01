<?php

class AxType1Font extends AxFont {
}

class AxStandardFont extends AxType1Font {
}

function ax_get_standard_font($font_name, $encoding = 'WinAnsiEncoding') {
	static $cache;
	ax_start_logging();
	
	/* deal with naming inconsistency */
	if($font_name == 'Times') {
		$font_name = 'Times-Roman';
	}
	
	/* look for font in cache */
	if($font = $cache[$font_name][$encoding]) {
		ax_end_logging();
		return $font;
	}

	/* load the pre-parsed serialize data */
	$dat_path = AX_FILE_ROOT . "/$font_name.dat";
	if(!($data = file_get_contents($dat_path))) {
		ax_end_logging("cannot open $dat_path");
		return false;
	}
	if(!($afm_data = unserialize($data))) {
		ax_end_logging("cannot unserialize $dat_path");
		return false;
	}

	$font = ax_create_type1_font($font_name, $afm_data, null, $encoding);	
	$cache[$font_name][$encoding] = $font;
	ax_end_logging();
	return $font;
}

function ax_load_type1_font($afm_path, $pfb_path, $encoding = 'WinAnsiEncoding') {
	static $cache;
	ax_start_logging();
	
	/* look for font in cache */
	if($font = $cache[$afm_path][$encoding]) {
		ax_end_logging();
		return $font;
	}

	/* parse the AFM file */
	$afm_data = ax_parse_type1_afm_file($afm_path);
	if(!$afm_data) {
		ax_end_logging("cannot parse $afm_path");
		return false;
	}

	/* load the font data  */
	if(!($pfb_data = file_get_contents($pfb_path))) {
		ax_end_logging("cannot open $pfb_path");
	}

	$font = ax_create_type1_font($font_name, $afm_data, $pfb_data, $encoding);	
	$cache[$font_name][$encoding] = $font;
	ax_end_logging();
	return $font;
}

function ax_create_encoding_dictionary($diff) {
	$encoding = ax_indirect_dictionary('Encoding');
	$array = array();
	$next = 0;
	foreach($diff as $code => $name) {
		if($next != $code) {
			$array[] = (float) $code;
		}
		$array[] = ax_name($name);
		$next = $code + 1;
	}
	$encoding['Differences'] = $array;
	return $encoding;
}

function ax_pack_type1_font(&$font_obj, $encoding_diff, $unicode_map, $embedding_attributes, $stream) {
	$font = ax_indirect_dictionary('Font');
	$font['Subtype'] = ax_name('Type1');
	$font['BaseFont'] = ax_name($font_obj->name);

	if(!is_a($font_obj, 'AxStandardFont') || $encoding_diff) {
		/* create width array */
		for($i = 32; $i <= 255; $i++) {
			$widths[] = (float) $font_obj->widths[chr($i)];
		}
		$font['FirstChar'] = 32.0;
		$font['LastChar'] = 255.0;
		$font['Widths'] = $widths;
			
		$font_descr = ax_indirect_dictionary('FontDescriptor');
		$font_descr['FontName'] = $font['BaseFont'];
		foreach($embedding_attributes as $name => $value) {
			$font_descr[$name] = $value;
		}
		$font_descr['FontFile'] = $stream;
		$font['FontDescriptor'] = $font_descr;
	}
	
	/* create encoding dictionary if it's not a built-in one */
	if($font_obj->encoding) {
		if($encoding_diff) {
			$font['Encoding'] = ax_create_encoding_dictionary($encoding_diff);
		}
		else {
			$font['Encoding'] = ax_name($font_obj->encoding);
		}
		
		if($unicode_map) {
			$font['ToUnicode'] = ax_create_tounicode_cmap($unicode_map, $font_obj->encoding);
		}
	}
	
	$font_obj->PDFStructure = $font;
	return true;
}

function ax_create_tounicode_cmap($unicode_map, $name) {
	/* find the cid to unicode ranges */
	$unicode_range_start = 0;
	$cid_range_start = 0;
	$cid_range_end = 0;
	$next_unicode = -1;
	$next_cid = -1;
	$cid_ranges = array();
	foreach($unicode_map as $cid => $unicode) {
		if($cid == $next_cid && $unicode == $next_unicode) {
			$cid_range_end = $cid;
			$next_unicode++;
			$next_cid++;
		}
		else {
			if($unicode_range_start) {
				$cid_ranges[] = sprintf('<%02x> <%02x> <%04x>', $cid_range_start, $cid_range_end, $unicode_range_start);
			}
		    $cid_range_start = $cid_range_end = $cid;
			$unicode_range_start = $unicode;
			$next_unicode = $unicode + 1;
			$next_cid = $cid + 1;
		}
	}
	$cid_ranges[] = sprintf('<%02x> <%02x> <%04x>', $cid_range_start, $cid_range_end, $unicode_range_start);

	/* write out the cmap text */
	$lines[] = "/CIDInit /ProcSet findresource";
	$lines[] = "begin";
	$lines[] = "12 dict";
	$lines[] = "begin";
	$lines[] = "begincmap";
	$lines[] = "/CIDSystemInfo <</Registry ($name) /Ordering ($name) /Supplement 0 >> def";
	$lines[] = "/CMapName /$name def 1";
	$lines[] = "/CMapType 1 def";
	$lines[] = "1 begincodespacerange";
	$lines[] = "<00> <FF>";
	$lines[] = "endcodespacerange";

	/* write out ranges in chunk of 100 */
	$cid_range_blocks = array_chunk($cid_ranges, 100);
	foreach($cid_range_blocks as $ranges) {
		$count = count($ranges);
		$lines[] = "$count beginbfrange";
		foreach($ranges as $range) {
			$lines[] = $range;
		}
		$lines[] = "endbfrange";
	}

	$lines[] = "endcmap";
	$lines[] = "CMapName";
	$lines[] = "currentdict";
	$lines[] = "/CMap";
	$lines[] = "defineresource";
	$lines[] = "pop";
	$lines[] = "end";
	$lines[] = "end";

	/* put it in a stream */
	$data = implode("\n", $lines);
	$stream = ax_create_stream($data);

	/* compress the stream if possible */
	if($fstream = ax_compress_stream($stream)) {
		$stream = $fstream;
	}

	return $stream;
}

function ax_parse_type1_afm_file($path) {
	if(!($data = file_get_contents($path))) {
		return false;
	}

	/* parse widths */
	$widths = array();
	$encoding = array();
	if(preg_match('/StartCharMetrics.*EndCharMetrics/s', $data, $m)) {
		$cm_data = $m[0];
		if(preg_match_all('/C\s+([-0-9]+)\s*;\s*WX\s+(\d+)\s*;\s*N\s+(\w+)\s*;\s*B\s+[^;]+;\s+/', $cm_data, $m, PREG_SET_ORDER)) {
			foreach($m as $s) {
				$code = (int) $s[1];
				$width = (int) $s[2];
				$name = $s[3];
				$widths[$name] = $width;
				$encoding[$code] = $name;
			}
		}
	}

	/* parse kerning pairs */
	$kerning_pairs = array();
	if(preg_match('/StartKernPairs\s+\d+.*EndKernPairs/s', $data, $m)) {
		$kp_data = $m[0];
		if(preg_match_all('/KPX\s+(\w+)\s+(\w+)\s+([-0-9]+)\s+/', $kp_data, $m, PREG_SET_ORDER)) {
			foreach($m as $s) {				
				$name1 = $s[1];
				$name2 = $s[2];
				$adj = intval($s[3]);
				$kerning_pairs[$name1][$name2] = $adj;
			}
		}
	}

	/* get properties of the font */
	$props = array();
	if(!preg_match('/^\s*FontName\s+(\S+)/m', $data, $m)) {
		return false;
	}
	$props['name'] = $m[1];
	if(!preg_match('/^\s*UnderlinePosition\s+([-0-9]+)/m', $data, $m)) {
		return false;
	}
	$props['underlinePosition'] = (int) $m[1];
	if(!preg_match('/^\s*UnderlineThickness\s+([-0-9]+)/m', $data, $m)) {
		return false;
	}
	$props['underlineThickness'] = $props['strikeoutThickness'] = (int) $m[1];

	/* get info for font descriptor */
	$descr = array();
	if(preg_match('/^\s*FontBBox\s+([-0-9]+)\s+([-0-9]+)\s+([-0-9]+)\s+([-0-9]+)/m', $data, $m)) {
		$descr['FontBBox'] = array(floatval($m[1]), floatval($m[2]), floatval($m[3]), floatval($m[4]));
	}
	if(preg_match('/^\s*Ascender\s+([-0-9]+)/m', $data, $m)) {
		$descr['Ascent'] = floatval($m[1]);
	}
	if(preg_match('/^\s*Descender\s+([-0-9]+)/m', $data, $m)) {
		$descr['Descent'] = floatval($m[1]);
	}
	if(preg_match('/^\s*CapHeight\s+([-0-9]+)/m', $data, $m)) {
		$descr['CapHeight'] = $cap_height = floatval($m[1]);
		$props['strikeoutPosition'] = $cap_height / 2;
	}
	if(preg_match('/^\s*XHeight\s+([-0-9]+)/m', $data, $m)) {
		$descr['XHeight'] = floatval($m[1]);
	}
	if(preg_match('/^\s*StdHW\s+([-0-9]+)/m', $data, $m)) {
		$descr['StemH'] = floatval($m[1]);
	}
	if(preg_match('/^\s*StdVW\s+([-0-9]+)/m', $data, $m)) {
		$descr['StemV'] = floatval($m[1]);
	}

	$flags = 0x0020;
	if(preg_match('/^\s*ItalicAngle\s+([-0-9]+)/m', $data, $m)) {
		if(($descr['ItalicAngle'] = floatval($m[1])) > 0) {
			$flags |= 0x0040;
		}
	}
	if(preg_match('/^\s*IsFixedPitch\s+(\w+)/m', $data, $m)) {
		if(strcasecmp($m[1], 'true') == 0) {
			$flags |= 0x0001;
		}
	}
	if(preg_match('/^\s*CharacterSet\s+([-0-9]+)/m', $data, $m)) {
		/* see if font contain symbols */
		if(strcasecmp($m[1], 'Special') == 0) {
			$flags &= ~0x0020;
			$flags |= 0x0004;
		}
	}		
	$descr['Flags'] = (float) $flags;

	return array($props, $widths, $kerning_pairs, $encoding, $descr);
}

function ax_transform_type1_metrics($widths_by_name, $kerning_pairs_by_name, $name_map) {
	/* inverse the map */
	$name_to_char = array_map('chr', array_flip($name_map));

	/* map normalized widths to codepoints */
	$widths = array();
	foreach($name_to_char as $name => $c) {
		$widths[$c] = $widths_by_name[$name];
	}

	/* map normalized kerning adjustments to codepoints */	
	$kerning_pairs = array();
	$keys1 = array_intersect(array_keys($kerning_pairs_by_name), $name_map);
	foreach($keys1 as $name1) {
		$c1 = $name_to_char[$name1];
		$list = $kerning_pairs_by_name[$name1];
		$keys2 = array_intersect(array_keys($list), $name_map);
		foreach($keys2 as $name2) {
			$c2 = $name_to_char[$name2];
			$kerning_pairs[$c1 . $c2] = $list[$name2];
		}
	}
	
	return array($widths, $kerning_pairs);
}

function ax_is_standard_font($name) {
	static $std_font_names;
	if(is_null($std_font_names)) {
		$std_font_names = array("Courier", "Helvetica", "Times-Roman", "Symbol", 
								"Courier-Bold", "Helvetica-Bold", "Times-Bold", "ZapfDingbats", 
								"Courier-Oblique", "Helvetica-Oblique", "Times-Italic", 
								"Courier-BoldOblique", "Helvetica-BoldOblique", "Times-BoldItalic");
	}
	if($name[0] == "\x1B") {
		in_array(substr($name, 1), $std_font_names);
	}
	else {
		return in_array($name, $std_font_names);
	}
}

function ax_create_type1_font($font_name, $afm_data, $pfb_data, $encoding) {
	list($props, $widths_by_name, $kerning_pairs_by_name, $default_encoding, $font_descr) = $afm_data;

	/* get encoding info */
	$encoding_diff = null;
	$name_map = $default_encoding;
	$unicode_map = null;
	if($encoding != 'default') {
		if(!($name_map = ax_get_postscript_cname_map($encoding))) {
			ax_end_logging("cannot load character map for $encoding");
			return false;
		}
		/* find the difference from the default encoding */
		$binded = array_diff_assoc($name_map, $default_encoding);		
		$notdef = array_diff(array_keys($default_encoding), array_keys($name_map));
		$encoding_diff = $binded;
		foreach($notdef as $index) {
			$encoding_diff[$index] = '.notdef';
		}
		
		if(!ax_is_predefined_encoding($encoding)) {
			$unicode_map = ax_get_unicode_map($encoding);
		}
	}

	/* get width and kerning table for this encoding */
	list($widths, $kerning_pairs) = ax_transform_type1_metrics($widths_by_name, $kerning_pairs_by_name, $name_map);

	/* place font data in a stream */
	$stream = null;
	if($pfb_data) {
		$stream = ax_create_stream($pfb_data);
		if($fstream = ax_compress_stream($stream)) {
			$stream = $fstream;
		}
			
		/* look for various offsets */
		$encrypted_start_index = strpos($pfb_data, "currentfile eexec\r") + 18;
		$encrypted_end_index = strpos($pfb_data, "0000000000000000000000000000000000000000000000000000000000000000"); 	
		$stream->dictionary['Length1'] = (float) $encrypted_start_index;
		$stream->dictionary['Length2'] = (float) $encrypted_end_index - $encrypted_start_index;
		$stream->dictionary['Length3'] = (float) strlen($pfb_data) - $encrypted_end_index;
	}
		
	/* create the font object */
	$font = new AxStandardFont();
	$font->name = $font_name;
	$font->widths = $widths;
	$font->kerningPairs = $kerning_pairs;
	$font->encoding = $encoding;
	$font->scaleFactor = 0.001;
	unset($props['name']);
	$font->attributes = $props;
	ax_pack_type1_font($font, $encoding_diff, $unicode_map, $font_descr, $stream);
	return $font;
}

?>