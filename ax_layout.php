<?php

function ax_create_text($text, $x, $y, $style, $options = AX_LEFT_JUSTIFY) {
	ax_start_logging();
	$x *= 72;
	$y *= 72;
	
	if(!is_a($style, 'AxTextStyle')) {
		ax_end_logging("argument 2 must be an AxTextStyle object");
		return false;
	}
	$justify = $options & 0x0000000F; 
	$v_align = $options & 0x000000F0;
	$style_contexts = ax_build_style_contexts(array($style), array());
	extract($style_contexts[0]);
	list($width, $kernings) = ax_get_text_metrics($text, $font, $horiz_scale, $char_spacing);
	
	$text_obj = new AxText;
	$text_obj->text = $text;
	$text_obj->kernings = $kernings;
	$text_obj->style = $text_style;
	$text_obj->width = $width;
	if($justify == AX_RIGHT_JUSTIFY) {
		$text_obj->left = $x - $width;
	}
	else if($justify == AX_CENTER_JUSTIFY) {
		$text_obj->left = $x - ($width / 2);
	}	
	else {
		$text_obj->left = $x;
	}
	if($v_align == AX_TOP_ALIGN) {
		$text_obj->bottom = $y - $text_height;
	}
	else if($v_align == AX_CENTER_ALIGN) {
		$text_obj->bottom = $y - ($text_height / 2);
	}
	else {
		$text_obj->bottom = $y;
	}
	$text_obj->height = $text_height;
	ax_end_logging();
	return $text_obj;
}

function ax_apply_padding($region, $padding) {
	$new_rects = array();
	if(is_int($padding)) {
		$padding = array($padding, $padding, $padding, $padding);
	}
	foreach($region->rectangles as $i => $rect) {
		$rect[0] += $padding[0];	// left
		$rect[2] -= $padding[2];	// right
		if(!isset($region->rectangles[$i + 1]) || $region->rectangles[$i + 1][3] != $rect[1]) {
			$rect[1] -= $padding[1];	// bottom
		}
		if(!isset($region->rectangles[$i - 1]) || $region->rectangles[$i - 1][1] != $rect[3]) {
			$rect[3] -= $padding[3];	// top
		}
		$new_rects[] = $rect;
	}
	$region->rectangles = $new_rects;
	return $region; 
}

function ax_lay_out_text($text, $region, $style, $options = AX_LEFT_JUSTIFY, $padding = false) {
	ax_start_logging();
	$justify = $options & 0x0000000F; 
	$v_align = $options & 0x000000F0;

	/* apply padding to region if necessary */
	if($padding) {
		$region = ax_apply_padding($region, $padding);
	}

	/* create a line allocator */
	$alloc = ax_create_line_allocator($region);

	/* if $style is an array, treat text as marked-up */
	if($text_is_marked_up = is_array($style)) {
		$style_objs = $style;
		$segments = ax_parse_marked_up_text($text);
	}
	else {
		$style_objs = array( 0 => $style);
		$segments = array(array($text, 0));
	}

	/* build the style contexts that will be referenced by the text */
	$style_keys = array();
	foreach($segments as $segment) {
		$style_keys[] = $segment[1];
	}
	$style_keys = array_unique($style_keys);
	$style_contexts = ax_build_style_contexts($style_objs, $style_keys);

	$remaining_text = '';
	$objects = array();
	$lines = array();
	$text_width = 0;
	$space_remaining = true;
	$pre_delim_width = 0;
	$pre_delim = '';
	$v_advance = 0;
	$baseline = AX_INFINITE;

	$segment_index = 0;
	foreach($segments as $segment) {
		list($text, $style_key) = $segment;
		/* extract style context into variable space */
		extract($style_contexts[$style_key]);
		$v_advance = max($v_advance, $line_height);
	
		$room = ax_allocate_line($alloc, $text_height);		
		$avail_width = $room[2] - $room[0];
		$space_width = ax_get_space_width($font, $horiz_scale, $char_spacing, $word_spacing);

		unset($last_obj);

		/* break text into tokens */
		$tokens = preg_split('/(?<=-)|(\s+)/s', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);		

		/* loop through the tokens */
		$index = 0;
		$last_index = count($tokens) - 1;
		foreach($tokens as $token) {
			$last_token = ($index == $last_index);
			if($token[0] > ' ') {
				/* a word, see how wide it is */
				list($token_width, $kernings) = ax_get_text_metrics($token, $font, $horiz_scale, $char_spacing);
				$delimited_text_width = $pre_delim_width + $token_width;

				$can_append = false;
				if($text_width + $delimited_text_width <= $avail_width) {
					$can_append = true;
					if(count($line) > 0) {
						if($justify == AX_RIGHT_JUSTIFY) {
							$can_append = ax_shift_line_towards_left($line, $delimited_text_width);
						}				
						else if($justify == AX_CENTER_JUSTIFY) {
							$can_append = ax_shift_line_towards_left($line, $delimited_text_width / 2);
						}
					}
					if($can_append) {
						if(isset($last_obj)) {
							ax_append_text_object_ex($last_obj, $pre_delim, $token, $delimited_text_width, $kernings);
							$text_width += $delimited_text_width;
						}
						else {
							if(!isset($line)) {
								$line = array();
								$lines[] =& $line;
							}
							$last_obj = ax_create_text_object_ex($pre_delim, $token, $text_style, $delimited_text_width, $text_height, $kernings, $room, $text_width, $justify);
							
							$baseline = min($baseline, $last_obj->bottom);
							$line[] =& $last_obj;
							$text_width += $delimited_text_width;
						}
					}
				}
				if(!$can_append) {
					if($line) {
						if($justify == AX_FULL_JUSTIFY) {
							ax_apply_full_justification($line);
						}

						/* if the line has more than one object, make sure they all sit on the same baseline */
						if(count($line) > 1) {
							ax_adjust_line_vertical_positions($line, $baseline, $v_advance, $v_align);
						}
					}
					$text_width = 0;
					unset($line);
					unset($last_obj);
					do {
						/* keep advancing the line cursor until we get a line that fits */
						if($space_remaining = ax_advance_line_cursor($alloc, $v_advance)) {
							$v_advance = $line_height;
							$room = ax_allocate_line($alloc, $text_height);
							$avail_width = $room[2] - $room[0];
							if($token_width <= $avail_width) {
								$line = array();
								$lines[] =& $line;
								$last_obj = ax_create_text_object_ex('', $token, $text_style, $token_width, $text_height, $kernings, $room, 0, $justify);
								$line[] =& $last_obj;
								$baseline = $last_obj->bottom;
								$text_width = $token_width;
								break;
							}
						}
					} while($space_remaining);
					if(!$space_remaining) {
						break;
					}
				}
				$pre_delim = '';
				$pre_delim_width = 0;
			}
			else {
				if($token == ' ') {
					/* a single space is common enough--save some time by not looking into a loop */
					$pre_delim .= $token;
					$pre_delim_width += $space_width;
				}
				else {
					for($i = 0; $i < strlen($token); $i++) {
						$c = $token[$i];
						if($c == ' ') {
							$pre_delim .= $c;
							$pre_delim_width += $space_width;
						}
						else if($c == "\t") {
							$pre_delim .= '        ';
							$pre_delim_width += $space_width * 8;
						}
						else if($c == "\n") {
							if(count($line) > 0) {
								ax_adjust_line_vertical_positions($line, $baseline, $v_advance, $v_align);
							}
							unset($line);
							unset($last_obj);
							$text_width = 0;
							$pre_delim = '';
							$pre_delim_width = 0;
							if($space_remaining = ax_advance_line_cursor($alloc, $v_advance)) {
								$v_advance = $line_height;
								$room = ax_allocate_line($alloc, $text_height);
								$avail_width = $room[2] - $room[0];
							}
							else {
								break;
							}
						}
					}
					if(!$space_remaining) {
						break;
					}
				}
				if($last_token) {
					if($text_width + $pre_delim_width <= $avail_width) {
						if(isset($last_obj)) {
							ax_append_text_object_ex($last_obj, $pre_delim, '', $pre_delim_width, null);
							$text_width += $pre_delim_width;
						}
						else {
							if(!isset($line)) {
								$line = array();
								$lines[] =& $line;
							}
							$last_obj = ax_create_text_object_ex($pre_delim, '', $text_style, $pre_delim_width, $text_height, null, $room, $pre_delim_width, $justify);
							$baseline = min($baseline, $last_obj->bottom);
							$line[] =& $last_obj;
							$text_width += $pre_delim_width;
						}
					}
					$pre_delim = '';
					$pre_delim_width = 0;
				}
			}
			$index++;
		}
		if(!$space_remaining) {
			$remaining_tokens = array_slice($tokens, $index);
			$remaining_text = implode('', $remaining_tokens);		
			if($text_is_marked_up) {
				$remaining_segments = array_slice($segments, $segment_index);
				$remaining_segments[0] = array($remaining_text, $style_key);
				$remaining_text = ax_unparse_marked_up_text($remaining_segments);
			}
			break;
		}
		$segment_index++;
	}
	unset($last_obj);
	unset($line);

	/* unset the properties temporarily attached to objects */
	ax_layout_text_clean_up($lines);

	ax_end_logging();
	return ($options & AX_RETURN_LEFTOVER) ? array($lines, $remaining_text) : $lines;
}

function ax_create_standard_styles($font_name, $font_size, $encoding = 'WinAnsiEncoding') {
	ax_start_logging();
	/* figure out the names of the fonts */
	if($font_name == "Times") {
		$font_name = "Times-Roman";
	}
	switch($font_name) {
		case "Times-Roman":
			$italic_name = "Times-Italic";
			$bold_name = "Times-Bold";
			$bolditalic_name = "Times-BoldItalic";
		break;
		case "Helvetica":
			$italic_name = "Helvetica-Oblique";
			$bold_name = "Helvetica-Bold";
			$bolditalic_name = "Helvetica-BoldOblique";
		break;
		case "Courier":
			$italic_name = "Courier-Oblique";
			$bold_name = "Courier-Bold";
			$bolditalic_name = "Courier-BoldOblique";
		break;
	}

	/* create the style objects */
	$default = new AxTextStyle;
	$default->font = ax_get_standard_font($font_name, $encoding);
	$default->fontSize = $font_size;
	$bold = new AxTextStyle;
	$bold->font = ax_get_standard_font($bold_name, $encoding);
	$italic = new AxTextStyle;
	$italic->font = ax_get_standard_font($italic_name, $encoding);
	$bolditalic = new AxTextStyle;
	$bolditalic->font = ax_get_standard_font($bolditalic_name, $encoding);
	$underline = new AxTextStyle;
	$underline->decorations = AX_UNDERLINE;
	$superscript = new AxTextStyle;
	$superscript->transform = AX_SUPERSCRIPT;
	$subscript = new AxTextStyle;
	$subscript->transform = AX_SUBSCRIPT;
	$strikethrough = new AxTextStyle;
	$strikethrough->decorations = AX_LINE_THROUGH;

	$style_array = array(
		$default,
		'b' => $bold,
		'i' => $italic,
		'b i' => $bolditalic,
		'i b' => $bolditalic,
		'u' => $underline,
		'super' => $superscript,
		'sub' => $subscript,
		's' => $strikethrough,
	);
	
	ax_end_logging();
	return $style_array;
}

function ax_overlay_grid_on_document(&$doc_obj) {
	for($i = 0; $i < count($doc_obj->pages); $i++) {
		$page =& $doc_obj->pages[$i];
		ax_overlay_grid_on_page($page);
	}
}

class AxLineAllocator {
	var $rects;
	var $v;
	var $index;
	var $max_left;
	var $max_right;
}

function ax_create_line_allocator($region) {
	$alloc = new AxLineAllocator;
	$alloc->rects = $region->rectangles;
	$alloc->index = 0;
	$alloc->v = @$alloc->rects[0][3];
	return $alloc;
}

function ax_allocate_line($alloc, $height) {	
	if($alloc->index < count($alloc->rects)) {
		list($left, $bottom, $right, $top) = $alloc->rects[$alloc->index];
		$t_top = $alloc->v;
		$t_bottom = $t_top - $height;
		if($t_bottom >= $bottom) {
			return array($left, $t_bottom, $right, $t_top);
		}
		else {
			$right_bound = $right;
			$left_bound = $left;
			$prev_bottom = $bottom;
			for($i = $alloc->index + 1; $i < count($alloc->rects); $i++) {
				list($left, $bottom, $right, $top) = $alloc->rects[$i];
				$right_bound = min($right_bound, $right);
				$left_bound = max($left_bound, $left);
				if($top < $prev_bottom) {
					break;
				}
				if($t_bottom >= $bottom) {
					return array($left_bound, $t_bottom, $right_bound, $t_top);
				}
				$prev_bottom = $bottom;
			}
		}
	}
	return array(0, 0, 0, 0);
}

function ax_advance_line_cursor(&$alloc, $v_advance) {
	if($alloc->index < count($alloc->rects)) {
		list($left, $bottom, $right, $top) = $alloc->rects[$alloc->index];
		$alloc->v -= $v_advance;
		if($alloc->v >= $bottom) {
			return true;
		}
		else {
			$prev_bottom = $bottom;
			while(++$alloc->index < count($alloc->rects)) {
				list($left, $bottom, $right, $top) = $alloc->rects[$alloc->index];
				if($top == $prev_bottom) {
					return true;
				}
				else {
					$alloc->v = $top;
					return true;
				}
			}		
		}
	}
	return false;
}

function ax_build_style_contexts($styles, $style_keys) {
	$contexts = array();

	/* make sure the default style has all properties set */
	$def_style = $styles[0];
	if(!$def_style) {
		$def_style = new AxTextStyle;
	}
	if(is_null($def_style->font)) {
		$def_style->font = ax_get_standard_font('Helvetica');
	}
	if(is_null($def_style->fontSize)) {
		$def_style->fontSize = 12;
	}
	if(is_null($def_style->lineSpacing)) {
		$def_style->lineSpacing = 1;
	}
	if(is_null($def_style->characterSpacing)) {
		$def_style->characterSpacing = 0;
	}
	if(is_null($def_style->wordSpacing)) {
		$def_style->wordSpacing = 0;
	}
	if(is_null($def_style->transform)) {
		$def_style->transform = AX_NORMAL;
	}
	if(is_null($def_style->color)) {
		$def_style->color = 0;
	}

	$def_context = array();
	ax_apply_style_object($def_style, $def_context);
	$contexts[0] = $def_context;

	// divide the style objects by depth
	foreach($styles as $key => $style_obj) {
		if($key !== 0) {
			$tags = preg_split('/\s+/', $key, -1, PREG_SPLIT_NO_EMPTY);
			$depth = count($tags);
			$styles_at_depth[$depth][] = array($tags, $style_obj);
		}
	}
	
	/* find the cascaded style of each key */
	foreach($style_keys as $key) {
		if($key !== 0) {
			$tags = explode(' ', $key);
			$depth = count($tags);
			$applicable_styles = array();
	
			/* match at the lowest depth first */
			for($i = 1; $i <= $depth; $i++) {
				if($rules = $styles_at_depth[$i]) {
					foreach($rules as $rule) {
						list($r_tags, $style_obj) = $rule;
						/* for a style to apply, all specific tags must be present in the correct order	*/
						$m1 = array_intersect($tags, $r_tags);
						$m2 = array_values(array_unique($m1));
						if($m2 == $r_tags) {
							/* find the index of the last matching tag */
							$keys = array_keys($m1, end($m2));
							$last_key = end($keys);
			
							/* style closer to the end are apply later */
							$applicable_styles[$last_key << 16 | $i] = $style_obj;
						}
					}
				}
			}
			ksort($applicable_styles);
			$c = count($applicable_styles);
			
			$context = $def_context;
			foreach($applicable_styles as $style_obj) {
				ax_apply_style_object($style_obj, $context);
			}
			$contexts[$key] = $context;
		}
	}
	
	foreach($contexts as $key => $context) {
		$name = $context['font']->name;
	}

	return $contexts;
}

function ax_get_recommended_adjustments($font, $type)  {
	if($type == AX_SUPERSCRIPT) {
		$yoffset = $font->attributes['superscriptYOffset'];
		$xsize = $font->attributes['superscriptXSize'];
		$ysize = $font->attributes['superscriptYSize'];
		$v_trans = ($yoffset) ? $yoffset * $font->scaleFactor : 0.50;
	}
	else if($type == AX_SUBSCRIPT) {
		$yoffset = - $font->attributes['subscriptYOffset'];
		$xsize = $font->attributes['subscriptXSize'];
		$ysize = $font->attributes['subscriptYSize'];
		$v_trans = ($yoffset) ? $yoffset * $font->scaleFactor : -0.15;
	}
	else {
		return array(0, 1, 1);
	}
	$scale = ($ysize) ? $ysize * $font->scaleFactor : 0.50;
	$aspect_ratio = ($ysize && $xsize) ? $xsize / $ysize : 1;
	return array($v_trans, $scale, $aspect_ratio);
}

function ax_apply_style_object($style_obj, &$context) {
	// create references to array elements that will be accessed more than once
	$text_style =& $context['text_style'];
	$line_spacing =& $context['line_spacing']; 
	$transform =& $context['transform'];
	$font_size =& $text_style['fontSize'];
	$scale =& $text_style['scale'];
	$aspect_ratio =& $text_style['aspectRatio'];
	$y_trans =& $text_style['yTranslation'];

	if(!is_null($style_obj->font)) {
		$context['font'] = $text_style['font'] = $style_obj->font;
	}
	if(!is_null($style_obj->fontSize)) {
		$font_size = $style_obj->fontSize;
	}
	if(!is_null($style_obj->characterSpacing)) {
		$context['char_spacing'] = $text_style['characterSpacing'] = $style_obj->characterSpacing;
	}
	if(!is_null($style_obj->wordSpacing)) {
		$context['word_spacing'] = $text_style['wordSpacing'] = $style_obj->wordSpacing;
	}
	if(!is_null($style_obj->transform)) {
		$transform = $style_obj->transform;
		if(is_array($transform)) {
			$y_trans = $transform['verticalTranslation'];
			$scale = $transform['scale'];
			$aspect_ratio = $transform['aspectRation'];
		}
		else {
			if($transform == AX_NORMAL) {
				$y_trans = 0;
				$scale = 1;
				$aspect_ratio = 1;
			}
			else {
				list($y_trans, $scale, $aspect_ratio) = ax_get_recommended_adjustments($text_style['font'], $transform);
			}
		}
	}
	if(!is_null($style_obj->lineSpacing)) {
		$line_spacing = $style_obj->lineSpacing;
	}
	if(!is_null($style_obj->decorations)) {		
		$text_style['decorations'] = $style_obj->decorations;
	}
	if(!is_null($style_obj->color)) {
		$text_style['color'] = $style_obj->color;
	}
	$context['text_height'] = $font_size;
	$context['line_height'] = $font_size * $line_spacing;
	$context['horiz_scale'] = $font_size * $scale * $aspect_ratio;
}

function ax_create_text_object_ex($delimiter, $text, $style, $width, $height, $kernings, $room, $offset, $justify) {
	$text_obj = new AxText;
	if($delimiter) {
		$text_obj->text = $delimiter . $text;
		if($kernings) { 
			$text_obj->kernings = array();
			$delim_len = strlen($delimiter);
			foreach($kernings as $index => $adj) {
				$text_obj->kernings[$delim_len + $index] = $adj;
			}
		}
	}
	else {
		$text_obj->text = $text;
		$text_obj->kernings = $kernings;
	}
	$text_obj->style = $style;
	$text_obj->width = $width;
	$text_obj->boundary = $room;
	if($justify == AX_RIGHT_JUSTIFY) {
		$text_obj->left = $room[2] - $width;
	}
	else if($justify == AX_CENTER_JUSTIFY) {
		$text_obj->left = $room[0] + ($room[2] - $room[0] - $width + $offset) / 2;
	}	
	else {
		$text_obj->left = $room[0] + $offset;
	}
	$text_obj->bottom = $room[1];
	$text_obj->height = $height;
	return $text_obj;
}

function ax_append_text_object_ex(&$obj, $delimiter, $text, $width, $kernings) {
	if($kernings) {
		$offset = strlen($delimiter) + strlen($obj->text);
		settype($obj->kernings, 'array');
		foreach($kernings as $index => $adj) {
			$obj->kernings[$offset + $index] = $adj;
		}
	}
	$obj->text .= $delimiter . $text;
	$obj->width += $width;
}

function ax_shift_line_towards_left(&$line, $delta) {
	foreach($line as $index => $e) {
		$obj =& $line[$index];
		if($obj->left - $delta < $obj->boundary[0]) {
			return false;
		}
	}
	foreach($line as $index => $e) {
		$obj =& $line[$index];
		$obj->left -= $delta;
	}
	return true;
}

function ax_adjust_line_vertical_positions($line, $baseline, $line_height, $v_align) {
	if($v_align == AX_BOTTOM_ALIGN) {
		for($i = 0; $i < count($line); $i++) {
			$obj =& $line[$i];
			$obj->bottom = $baseline;
		}
	}
	else if($v_align == AX_CENTER_ALIGN) {
		for($i = 0; $i < count($line); $i++) {
			$obj =& $line[$i];
			$adj = ($line_height - $obj->height) / 2;
			$obj->bottom = $baseline + $adj;
		}		
	}
}

function ax_apply_full_justification($line) {
	/* see how much extra space there is */
	$last = $line[count($line) - 1];
	$extra = $last->boundary[2] - $last->left - $last->width;

	/* get the the some info about each object */
	$char_counts = array();
	$space_counts = array();
	$font_size = array();
	for($i = 0; $i < count($line); $i++) {
		$obj =& $line[$i];
		$char_counts[] = strlen($obj->text);
		$space_counts[] = substr_count($obj->text, ' ');
		$font_sizes[] = $obj->style['fontSize'];
	}
	$total_char_count = array_sum($char_counts);
	$total_space_count = array_sum($space_counts);

	/* adjust the ratio of word-spacing to character-spacing based on average length of words */
	$ws_contri = min(1, $total_space_count / $total_char_count * 4.5);
	$cs_contri = 1 - $ws_contri;

	/* see how much each object should contribute */
	$ws_weights = array();
	$cs_weights = array();
	foreach($font_sizes as $i => $font_size) {
		/* bigger texts contribute more */
		$ws_weights[] = $space_counts[$i] * $ws_contri * $font_size;
		$cs_weights[] = $char_counts[$i] * $cs_contri * $font_size;
	}
	$total_weight = array_sum($ws_weights) + array_sum($cs_weights);
	$advance = 0;

	for($i = 0; $i < count($line); $i++) {
		$obj =& $line[$i];
		if($total_weight) {
			/* calcuate the expansion allocated to this object */
			$ws_weight = $ws_weights[$i];
			$cs_weight = $cs_weights[$i];
			$ws_expansion = $extra * $ws_weight / $total_weight;
			$cs_expansion = $extra * $cs_weight / $total_weight;
			$expansion = $ws_expansion + $cs_expansion;
			$avail = $obj->boundary[2] - ($obj->left + $advance + $obj->width);
			if($expansion > $avail) {
				/* doesn't have room to expand that much, try to do our best */
				$ws_expansion = $avail * ($ws_weight / ($ws_weight + $cs_weight));
				$cs_expansion = $avail - $ws_expansion;			
				$expansion = $ws_expansion + $cs_expansion;
			}
			/* adjust the word and character spacing */
			$hscale = $obj->style['aspectRatio'];
			if($ws_expansion) {
				$obj->style['wordSpacing'] += $ws_expansion / $space_counts[$i] / $hscale;
			}
			if($cs_expansion) {
				$obj->style['characterSpacing'] += $cs_expansion / $char_counts[$i] / $hscale;
			}
			/* move object to accommadate prior adjustments */
			$obj->left += $advance;
			$obj->width += $expansion;

			/* substract from the amount that remaining objects need to take */
			$extra -= $expansion;
			$advance += $expansion;
			$total_weight -= $ws_weight + $cs_weight;
		}
		else {
			/* no expansion for this object, just move it */
			$obj->left += $advance;
		}
	}
}

function ax_parse_marked_up_text($s) {
	$parts = preg_split('/(<[\/\w]+>)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
	$segments = array();
	$tag_chain = array(); 
	foreach($parts as $part) {
		if($part[0] == '<') {
			if($part[1] == '/') {
				$end_tag = substr($part, 2, -1);
				$wrong_tags = array();
				while($last = array_pop($tag_chain)) {
					if($last == $end_tag) {
						break;
					}
					/* unbalanced start/end tags, put it back in later */
					$wrong_tags[] = $last;
				}
				foreach($wrong_tags as $tag) {
					$tag_chain[] = $tag;
				}
			}
			else {
				$start_tag = substr($part, 1, -1);
				$tag_chain[] = $start_tag;
			}
		}
		else {
			$text = html_entity_decode($part);
			$style_key = ($tag_chain) ? implode(' ', $tag_chain) : 0;
			$segments[] = array($text, $style_key);
		}
	}
	return $segments;
}

function ax_unparse_marked_up_text($segments) {
	$text = '';
	$tag_stack = array();	
	foreach($segments as $segment) {
		list($s, $tags) = $segment;
		$s = htmlspecialchars($s);		
		$tag_chain = ($tags) ? explode(' ', $tags) : array();	
		$max = min(count($tag_chain), count($tag_stack));
		$index = 0;
		while($index < $max) {
			if($tag_chain[$index] !== $tag_stack[$index]) {
				break;
			}
			$index++;
		}
		$end_tags = array_slice($tag_stack, $index);
		$start_tags = array_slice($tag_chain, $index);
		
		$et = '';
		foreach($end_tags as $index => $tag) {
			$et = '</' . $tag . '>' . $et;
		}
		$st = '';
		foreach($start_tags as $index => $tag) {
			$st .= '<' . $tag . '>';
		}
		$text .= "$et$st$s";
		$tag_stack = $tag_chain;		
	}
	return $text;
}

function ax_layout_text_clean_up(&$lines) {
	for($i = 0; $i < count($lines); $i++) {
		$line =& $lines[$i];
		for($j = 0; $j < count($line); $j++) {
			$obj =& $line[$j];
			unset($obj->boundary);
		}
	}
}

function ax_overlay_grid_on_page(&$page) {
	$width = $page->width / 72;
	$height = $page->height / 72;
	
	for($h = 0.25; $h < $width; $h += 0.25) {	
		$page->elements[] = ax_create_line($h, 0, $h, $height, 0, 0x990000);
	}
	for($v = 0.25; $v < $height; $v += 0.25) {
		$page->elements[] = ax_create_line(0, $v, $width, $v, 0, 0x990000);
	}

	$style = new AxTextStyle;
	$style->font = ax_get_standard_font('Helvetica');
	$style->fontSize = 4;
	$style->color = 0x990000;
	for($h = 0.5; $h < $width; $h += 0.5) {
		for($v = 0.5; $v < $height; $v += 0.5) {
			$coord = "$h, $v";
			$page->elements[] = ax_create_text($coord, $h + 0.01, $v + 0.01, $style, AX_LEFT_JUSTIFY | AX_BOTTOM_ALIGN);
		}
	}
}

?>