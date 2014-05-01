<?php

class AxTrueTypeFont extends AxFont {
}

function ax_load_truetype_font($ttf_path, $encoding = 'WinAnsiEncoding') {
	static $cache;
	ax_start_logging();
	if($font = $cache[$ttf_path][$encoding]) {
		ax_end_logging();		
		return $font;
	}
	if(!($data = file_get_contents($ttf_path))) {
		ax_end_logging("cannot open $ttf_path");
	}
	
	if(!($font = ax_create_truetype_font_from_string($data, $encoding))) {
		ax_end_logging("cannot create font");	
	}
	$cache[$ttf_path][$encoding] = $font;
	ax_end_logging();
	return $font;	
}

function ax_create_truetype_font_from_string($data, $encoding = 'WinAnsiEncoding') {
	/* get the unicode map for this encoding */
	if(!($unicode_map = ax_get_unicode_map($encoding))) {
		ax_log_error("cannot obtain Unicode map for $encoding");	
		return false;
	}
	$char_to_unicode = array();
	foreach($unicode_map as $code => $unicode) {
		$char_to_unicode[chr($code)] = $unicode;
	}

	$offset_table = unpack_substr('Nversion/nnumTables/nsearchRange/nentrySelector/nrangeShift', $data, 0, 12);

	$table_dir = array();
	for($i = 0, $num_tables = $offset_table['numTables'], $offset = 12; $i < $num_tables; $i++, $offset += 16) {
		$entry = unpack_substr('Ntag/NcheckSum/Noffset/Nlength', $data, $offset, 16);
		$table_dir[$entry['tag']] = array($entry['offset'], $entry['length']);
	}

	/* get info for font descriptor */
	$props = array();
	$descr = array();
	$descr_flags = 0x0020;

	/* read the head table */
	if(!($head = ax_read_tt_head_table($data, $table_dir))) {
		return false;
	}
	$units_per_em = $head['unitsPerEm'];

	/* get the bounding rect */
	$pdf_unit_ratio = 1000 / $units_per_em;
	$descr['FontBBox'] = array(
		round($head['xMin'] * $pdf_unit_ratio),
		round($head['yMin'] * $pdf_unit_ratio),
		round($head['xMax'] * $pdf_unit_ratio),
		round($head['yMax'] * $pdf_unit_ratio));

	/* read the hhea table */
	if(!($hhea = ax_read_tt_hhea_table($data, $table_dir))) {
		return false;
	}

	/* read the post table */
	if(!($post = ax_read_tt_post_table($data, $table_dir))) {
		return false;
	}
	if(($descr['ItalicAngle'] = (float) $post['italicAngle']) > 0) {
		$descr_flags |= 0x0040;
	}
	$props['underlinePosition'] = $post['underlinePosition'];
	$props['underlineThickness'] = $post['underlineThickness'];
	if($post['isFixedPitch']) {
		$descr_flags |= 0x0001;
	}
	
 	/* read the OS/2 table if it's there */
	if($os2 = ax_read_tt_os2_table($data, $table_dir)) {
		$descr['Ascent'] =  round($os2['typoAscender'] * $pdf_unit_ratio);
		$descr['Descent'] = round($os2['typoDescender'] * $pdf_unit_ratio);
		$descr['AvgWidth'] = round($os2['avgCharWidth'] * $pdf_unit_ratio);
		$descr['Leading'] = round($os2['typeLineGap'] * $pdf_unit_ratio);
		$descr['CapHeight'] = $descr['Ascent'];

		$props['strikeoutThickness'] = $os2['strikeoutSize'];
		$props['strikeoutPosition'] = $os2['strikeoutPosition'];
		$props['subscriptYOffset'] = $os2['subscriptYOffset'];
		$props['subscriptXSize'] = $os2['subscriptXSize'];
		$props['subscriptYSize'] = $os2['subscriptYSize'];
		$props['superscriptYOffset'] = $os2['superscriptYOffset'];
		$props['superscriptXSize'] = $os2['superscriptXSize'];
		$props['superscriptYSize'] = $os2['superscriptYSize'];
	}
	else {
	}

	/* read the PCLT table if it's there */
	if($pclt = ax_read_tt_pclt_table($data, $table_dir)) {
		$descr['CapHeight'] = round(ax_tt_signed_int16($pclt['capHeight']) * $pdf_unit_ratio);
		$descr['XHeight'] = round(ax_tt_signed_int16($pclt['xHeight']) * $pdf_unit_ratio);
	}
	$descr['StemV'] = (float) 45;

	/* get the postscript name from the name table */
	if(!($ps_name = ax_get_tt_postscript_name($data, $table_dir))) {
		return false;
	}
	$descr['Flags'] = (float) $descr_flags;

	/* get the character-to-glyph-index table from cmap */
	$char_to_glyph = ax_get_tt_character_to_glyph_table($data, $table_dir, $char_to_unicode);

	/* get character widths from hmtx */
	$widths = ax_get_tt_glyph_metrics($data, $table_dir, $hhea['numMetrics'], $char_to_glyph);

	/* get kerning pairs from kern */
	$kerning_pairs = ax_get_tt_kerning_pairs($data, $table_dir, $char_to_glyph);

	/* put the font data in a stream, compress it if possible */
	$stream = ax_create_stream($data);
	if($fstream = ax_compress_stream($stream)) {
		$stream = $fstream;
	}

	$stream->dictionary['Length1'] = (float) strlen($data);

	/* create the font object */
	$font = new AxTrueTypeFont;
	$font->name = $ps_name;
	$font->widths = $widths;
	$font->kerningPairs = $kerning_pairs;
	$font->stream = $stream;	
	$font->attributes = $props;
	$font->scaleFactor = 1 / $units_per_em;
	$font->encoding = $encoding;
	ax_pack_truetype_font($font, $char_to_glyph, $unicode_map, $descr, $stream);
	
	return $font;
}

function ax_get_tt_table_data($font_data, $table_dir, $name) {
	$tag = ord($name[0]) << 24 | ord($name[1]) << 16 | ord($name[2]) << 8 | ord($name[3]);
	if(!($entry = @$table_dir[$tag])) {
		return false;
	}
	list($offset, $length) = $entry;
	if($offset + $length > strlen($font_data)) {
		return false;
	}
	return substr($font_data, $offset, $length);
}

function ax_read_tt_head_table($data, $table_dir) {
	if(!($head_data = ax_get_tt_table_data($data, $table_dir, 'head'))) {
		return false;
	}
	$head = unpack('NtableVersion/NfontRevision/NcheckSumAdjustment/NmagicNumber/nflags/nunitsPerEm/N2created/N2modified/nxMin/nyMin/nxMax/nyMax/nmacStyle/nlowestRecPPEM/nfontDirectionHint/nindexToLocFormat/nglyphDataFormat', $head_data);
	if($head['magicNumber'] != 0x5F0F3CF5) {
		return false;
	}
	ax_tt_fixed($head['tableVersion']);
	ax_tt_fixed($head['fontRevision']);
	$signed16_fields = array('xMin', 'yMin', 'xMax', 'yMax', 'fontDirectionHint');
	foreach($signed16_fields as $f) {
		ax_tt_signed_int16($head[$f]);
	}
	return $head;
}

function ax_read_tt_hhea_table($data, $table_dir) {
	if(!($hhea_data = ax_get_tt_table_data($data, $table_dir, 'hhea'))) {
		return false;
	}
	$hhea = unpack('NtableVersion/nascender/ndescender/nlineGap/nadvanceWidthMax/nminLeftSideBearing/nminRightSideBearing/nxMaxExtent/ncaretSlopeRise/ncareSlopRun/n5reserved/nmetricDataFormat/nnumMetrics', $hhea_data);
	ax_tt_fixed($head['tableVersion']);
	$signed16_fields = array('ascender', 'descender', 'lineGap', 'minLeftSideBearing', 'minRightSideBearing', 'xMaxExtent', 'caretSlopeRise', 'careSlopRun', 'caretOffset');
	foreach($signed16_fields as $f) {
		ax_tt_signed_int16($hhea[$f]);
	}
	return $hhea;
}

function ax_read_tt_post_table($data, $table_dir) {
	if(!($post_data = ax_get_tt_table_data($data, $table_dir, 'post'))) {
		return false;
	}
	$post = unpack('NformatType/NitalicAngle/nunderlinePosition/nunderlineThickness/NisFixedPitch/NminMemType42/NmaxMemType42/NminMemType1/NmaxMemType1', $post_data);
	ax_tt_fixed($post['version']);
	ax_tt_fixed($post['italicAngle']);
	ax_tt_signed_int16($post['underlinePosition']);
	ax_tt_signed_int16($post['underlineThickness']);
	return $post;
}

function ax_read_tt_os2_table($data, $table_dir) {
	if(!($os2_data = ax_get_tt_table_data($data, $table_dir, 'OS/2'))) {
		return false;
	}
	$os2 = unpack('nversion/navgCharWidth/nweightClass/nwidthClass/nfsType/nsubscriptXSize/nsubscriptYSize/nsubscriptXOffset/nsubscriptYOffset/nsuperscriptXSize/nsuperscriptYSize/nsuperscriptXOffset/nsuperscriptYOffset/nstrikeoutSize/nstrikeoutPosition/nfamilyClass/a10panose/N4unicodeRange/a4vendId/nselection/nfirstCharIndex/nlastCharIndex/ntypoAscender/ntypoDescender/ntypeLineGap/nwinAscent/nwinDescent/NcodePageRange1/NcodePageRange2', $os2_data);
	$signed16_fields = array('avgCharWidth', 'fsType', 'subscriptXSize', 'subscriptYSize', 'subscriptXOffset', 'subscriptYOffset', 'superscriptXSize', 'superscriptYSize', 'superscriptXOffset', 'superscriptYOffset', 'strikeoutSize', 'strikeoutPosition', 'familyClass', 'typoAscender', 'typoDescender', 'typeLineGap');
	foreach($signed16_fields as $f) {
		ax_tt_signed_int16($os2[$f]);
	}
	return $os2;
}

function ax_read_tt_pclt_table($data, $table_dir) {
	if(!($pclt_data = ax_get_tt_table_data($data, $table_dir, 'PCLT'))) {
		return false;
	}
	$pclt = unpack('Nversion/NfontNumber/npitch/nxHeight/nstyle/ntypeFamily/ncapHeight/nsymbolSet/a16typeFace/a8characterComplement/a6filename/CstrokeWeight/CwidthType/CserifStyle/Creserved', $pclt_data);
	return $pclt;
}

function ax_get_tt_postscript_name($data, $table_dir) {
	if(!($name_data = ax_get_tt_table_data($data, $table_dir, 'name'))) {
		return false;
	}
	$name = unpack("nformat/nnumRecords/noffset", $name_data);
	$num_records = $name['numRecords'];
	for($i = 0, $offset = 6; $i < $num_records; $i++, $offset += 12) {
		$name_rec = unpack_substr('nplatformId/nencodingId/nlangId/nnameId/nlength/noffset', $name_data, $offset, 12);
		if($name_rec['nameId'] == 6) {			
			$ps_name = substr($name_data, $name['offset'] + $name_rec['offset'], $name_rec['length']);
			$ps_name = str_replace("\0", "", $ps_name);
			return $ps_name;
		}
	}
	return false;
}

function ax_get_tt_character_to_glyph_table($data, $table_dir, $char_to_unicode) {
	if(!($cmap_data = ax_get_tt_table_data($data, $table_dir, 'cmap'))) {
		return false;
	}
 	$info = unpack_substr('ntableVersion/nnumEncodings', $cmap_data, 0, 4);
	$num_encodings = $info['numEncodings'];

	/* find the format 4 cmap */
	$format4 = null;
	for($i = 0, $offset = 4; $i < $num_encodings; $i++, $offset += 8) {
		$encoding = unpack_substr('nplatformId/nencodingId/Noffset', $cmap_data, $offset, 8);
		if($encoding['platformId'] == 3 && $encoding['encodingId'] == 1) {
			/* Microsoft Unicode */
			$subtable_offset = $encoding['offset'];
			$table = unpack_substr('nformat/nlength/nlanguage', $cmap_data, $subtable_offset, 6);
			if($table['format'] == 4) {
				$format4 = unpack_substr('nformat/nlength/nversion/nsegCountX2/nsearchRange/nentrySelector/nrangeShift', $cmap_data, $subtable_offset, 14);
				break;
			}			
		}
	}
	if(!$format4) {
		return false;
	}
	$seg = $format4['segCountX2'] / 2;
	$shorts = unpack_substr("n*", $cmap_data, $subtable_offset + 14, $format4['length'] - 14);
	$end_codes = array_slice($shorts, 0, $seg);
	$start_codes = array_slice($shorts, $seg + 1, $seg);
	$id_deltas = array_slice($shorts, $seg * 2 + 1, $seg);
	array_walk($id_deltas, 'ax_tt_signed_int16');
	$range_offsets = array_slice($shorts, $seg * 3 + 1);

	/* build the glyph-to-char table */
	$unicode_to_char = array_flip($char_to_unicode);
	$char_to_glyph = array();
	ksort($unicode_to_char);
	$end_code = current($end_codes);
	$start_code = current($start_codes);
	$id_delta = current($id_deltas);
	$range_offset = current($range_offsets);
	$index = 0;
	foreach($unicode_to_char as $unicode => $char) {
		while($unicode > $end_code) {
			$index++;
			$end_code = next($end_codes);
			$start_code = next($start_codes);
			$id_delta = next($id_deltas);
			$range_offset = next($range_offsets);
		}
		if($unicode >= $start_code) {
			if(!$range_offset) {
				$glyph_index = $unicode + $id_delta + 1;
			}
			else {
				/* weird way of finding the glyph index */
				$offset = $unicode - $start_code + ($range_offset >> 1);
				$glyph_index = $range_offsets[$index + $offset];
			}
			if($glyph_index >= 65536) {	
				$glyph_index -= 65536;
			} 
			$char_to_glyph[$char] = $glyph_index;
		}	
	}
	return $char_to_glyph;
}

function ax_get_tt_glyph_metrics($data, $table_dir, $num_matrics, $char_to_glyph) {
	if(!($hmtx_data = ax_get_tt_table_data($data, $table_dir, 'hmtx'))) {
		return false;
	}
	$widths = array();
	$shorts = unpack("n*", $hmtx_data);
	$last_width = $shorts[$num_matrics * 2 - 1];
	foreach($char_to_glyph as $char => $glyph_index) {
		if($glyph_index < $num_matrics) {
			$widths[$char] = $shorts[$glyph_index * 2 + 1];
		}
		else {
			$widths[$char] = $last_width;
		}
	}
	return $widths;
}

function ax_get_tt_kerning_pairs($data, $table_dir, $char_to_glyph) {
	if(!($kern_data = ax_get_tt_table_data($data, $table_dir, 'kern'))) {
		return null;
	}
	$kern = unpack_substr('ntableVersion/nnumSubTables', $kern_data, 0, 4);
	$num_subtables = $kern['numSubTables'];

	/* get the inversed relationship */
	$glyph_to_char = array_flip($char_to_glyph);

	$kerning_pairs = array();
	for($i = 0, $offset = 4; $i < $num_subtables; $i++) {
		$subtable = unpack_substr('nversion/nlength/ncoverage', $kern_data, $offset, 6);
		$coverage = $subtable['coverage'];
		$format = $coverage >> 8;
		/* format 0, horizontal, kerning-pairs, not cross-stream */
		if(($coverage >> 8) == 0 && ($coverage & 0x0001) && !($coverage & 0x0002) && !($coverage & 0x0004)) {
			$format0 = unpack_substr('nnumPairs/nsearchRange/nentrySelector/nrangeShift', $kern_data, $offset + 6, 8);
			$num_pairs = $format0['numPairs'];
			if($num_pairs > 0) {
				$shorts = unpack_substr('n*', $kern_data, $offset + 14, 6 * $num_pairs);
				for($left = current($shorts); $left; $left = next($shorts)) {
					$right = next($shorts);
					$value = next($shorts);
					ax_tt_signed_int16($value);
					if(($c1 = @$glyph_to_char[$left]) && ($c2 = @$glyph_to_char[$right])) {
						$kerning_pairs[$c1 . $c2] = $value;
					}
				}
			}
		}
		$offset += $subtable['length'];
	}
	return ($kerning_pairs) ? $kerning_pairs : null;
}

function ax_tt_signed_int16(&$d) {
	if($d & 0x8000) {
		$d = - $d ^ 0xFFFF;
	}
}

function ax_tt_fixed(&$d) {
	$d = $d / 65536;
}

function unpack_substr($format, $data, $offset, $len) {
	$bin = substr($data, $offset, $len);
	return unpack($format, $bin);
}

function ax_pack_truetype_font(&$font_obj, $char_to_glyph, $unicode_map, $embedding_attributes, $stream) {
	$font = ax_indirect_dictionary('Font');
	$font['BaseFont'] = ax_name($font_obj->name);
	
	if(ax_is_predefined_encoding($font_obj->encoding)) {
		$font['Subtype'] = ax_name('TrueType');
		$font['BaseFont'] = ax_name($font_obj->name);
		$font['Encoding'] = ax_name($font_obj->encoding);

		/* add width info */
		$widths = array();
		$scaling = 1000 * $font_obj->scaleFactor;
		for($i = 32; $i <= 255; $i++) {
			$widths[] = round($font_obj->widths[chr($i)] * $scaling);
		}
		$font['FirstChar'] = 32.0;
		$font['LastChar'] = 255.0;
		$font['Widths'] = $widths;

		$font_descr = array();
		$font_descr['Type'] = ax_name('FontDescriptor');
		$font_descr['FontName'] = $font['BaseFont'];
		foreach($embedding_attributes as $name => $value) {
			$font_descr[$name] = $value;
		}
		$font_descr['FontFile2'] = $stream;
		$font['FontDescriptor'] = $font_descr;
	}
	else {
		/* for encodings other than the predefined ones, specify as a Type0 font */
		$font['Subtype'] = ax_name('Type0');
		$font['BaseFont'] = ax_name($font_obj->name);

		/* the CID system info dictionary */
		$cid_sys_info = ax_indirect_dictionary();
		$cid_sys_info['Registry'] = $font_obj->encoding;
		$cid_sys_info['Ordering'] = $font_obj->encoding;
		$cid_sys_info['Supplement'] = 0.0;

		/* add a CID font as a descendant of the Type0 font */
		$cid_font = ax_create_cid_type2_font($font_obj, $char_to_glyph, $embedding_attributes, $stream, $cid_sys_info);
		$font['DescendantFonts'] = array($cid_font);

		/* add encoding cmap */
		$font['Encoding'] = ax_create_encoding_cmap($char_to_glyph, $font_obj->encoding, $cid_sys_info);

		/* add toUnicode cmap */
		$font['ToUnicode'] = ax_create_tounicode_cmap($unicode_map, $font_obj->encoding);
	}

	$font_obj->PDFStructure = $font;
	return true;
}

function ax_create_cid_type2_font(&$font_obj, $char_to_glyph, $embedding_attributes, $stream, &$cid_sys_info) {
	$font = ax_indirect_dictionary('Font');
	$font['Subtype'] = ax_name('CIDFontType2');
	$font['BaseFont'] = ax_name($font_obj->name);

	/* add width info */
	$w = array();
	$next_gid = -1;
	$scaling = 1000 * $font_obj->scaleFactor;
	$gid_widths = array();
	for($i = 32; $i <= 255; $i++) {
		$char = chr($i);
		$gid_widths[$char_to_glyph[$char]] = round($font_obj->widths[$char] * $scaling);
	}	
	ksort($gid_widths);
	foreach($gid_widths as $gid => $width) {
		if($gid == $next_gid) {
			$range_widths[] = (float) $width;
			$next_gid++;
		}
		else {
			unset($range_widths);
			$w[] = (float) $gid;
			$range_widths = array((float) $width);
			$w[] =& $range_widths;
			$next_gid = $gid + 1;
		}
	}
	unset($range_widths);
	$font['W'] = $w;

	$font_descr = ax_indirect_dictionary('FontDescriptor');
	foreach($embedding_attributes as $name => $value) {
		$font_descr[$name] = $value;
	}
	$font_descr['FontFile2'] = $stream;
	$font['FontDescriptor'] = $font_descr;
	$font['CIDSystemInfo'] =& $cid_sys_info;

	return $font;
}

function ax_create_encoding_cmap($char_to_glyph, $name, &$cid_sys_info) {
	/* find the cid to gid ranges */
	$gid_range_start = 0;
	$cid_range_start = 0;
	$gid_range_end = 0;
	$cid_range_end = 0;
	$next_gid = -1;
	$next_cid = -1;
	$cid_ranges = array();
	$cid_to_gid = array();
	foreach($char_to_glyph as $char => $gid) {
		$cid_to_gid[ord($char)] = $gid;
	}
	ksort($cid_to_gid);
	foreach($cid_to_gid as $cid => $gid) {
		if($cid == $next_cid && $gid == $next_gid) {
			$cid_range_end = $cid;
			$next_gid++;
			$next_cid++;
		}
		else {
			if($gid_range_start) {
				$cid_ranges[] = sprintf('<%02x> <%02x> %d', $cid_range_start, $cid_range_end, $gid_range_start);
			}
		    $cid_range_start = $cid_range_end = $cid;
			$gid_range_start = $gid;
			$next_gid = $gid + 1;
			$next_cid = $cid + 1;
		}
	}
	$cid_ranges[] = sprintf('<%02x> <%02x> %d', $cid_range_start, $cid_range_end, $gid_range_start);

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
		$lines[] = "$count begincidrange";
		foreach($ranges as $range) {
			$lines[] = $range;
		}
		$lines[] = "endcidrange";
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

	/* add addition fields to stream dictionary */
	$stream->dictionary['Type'] = ax_name('CMap');
	$stream->dictionary['CMapName'] = ax_name($name);
	$stream->dictionary['CIDSystemInfo'] =& $cid_sys_info;

	/* compress the stream if possible */
	if($fstream = ax_compress_stream($stream)) {
		$stream = $fstream;
	}

	return $stream;
}

?>