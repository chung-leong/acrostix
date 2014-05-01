<?php

function ax_fill_text_field(&$doc_obj, $field_name, $text, $style, $options = AX_LEFT_JUSTIFY, $padding = false) {
	ax_start_logging();
	$count = 0;
	if(ax_check_form_field($doc_obj, $field_name)) {
		$fields =& $doc_obj->PDFStructure['Root']['AcroForm']['Fields'];
		$field_objs =& $doc_obj->formFields[$field_name];
		foreach($field_objs as $index => $field_obj) {
			if($field_obj->type == 'Tx') {
				$subfields = array();
				if(is_array($field_obj->container)) {
					$kids =& $field_obj->PDFStructure['Kids'];
					$containers =& $field_obj->container;
					for($i = 0; $i < count($kids); $i++) {
						$subfields[] =& $kids[$i];
					}
				} 
				else {
					$containers = array();
					$subfields[] =& $field_obj->PDFStructure;
					$containers[] =& $field_obj->container;
				}
				/* create a region over the text field */

				for($i = 0; $i < count($subfields); $i++) {
					$field =& $subfields[$i];
					$container =& $containers[$i];
					$rect = $field['Rect'];
					$region = new AxRegion;
					
					/* move the boundary if text is allowed to overflow */
					if($options & AX_OVERFLOW_SIDES) {
						if($options & AX_CENTER_JUSTIFY) {
							$rect[0] -= AX_INFINITE / 2;
							$rect[2] += AX_INFINITE / 2;
						}
						else if($options & AX_RIGHT_JUSTIFY) {
							$rect[0] = - AX_INFINITE;
						}
						else if($options & AX_FULL_JUSTIFY) {
							ax_log_error("cannot use AX_OVERFLOW_SIDES with AX_FULL_JUSTIFY");
						}
						else {
							$rect[2] = AX_INFINITE;
						}
					}
					else if($options & AX_OVERFLOW_BOTTOM) {
						$rect[1] = - AX_INFINITE;
					}
					$region->rectangles = array($rect);	
		
					/* layout the text and add the resulting lines to the page */
					$lines = ax_lay_out_text($text, $region, $style, $options, $padding); 
					ax_add_page_elements($container, $lines);
					ax_remove_widget_annotation($container, $field);
				}

				/* remove the field from the document */
				unset($fields[$field_obj->index]);
				unset($field_objs[$index]);
				$count++;
			}
		}
	}
	ax_end_logging();
	return $count;
}

function ax_fill_text_column(&$doc_obj, $field_name, $text_rows, $style, $options = AX_LEFT_JUSTIFY, $padding = false) {
	ax_start_logging();
	$count = 0;
	if(ax_check_form_field($doc_obj, $field_name)) {
		$fields =& $doc_obj->PDFStructure['Root']['AcroForm']['Fields'];
		$field_objs =& $doc_obj->formFields[$field_name];
		foreach($field_objs as $index => $field_obj) {
			if($field_obj->type == 'Tx') {
				$subfields = array();
				if(is_array($field_obj->container)) {
					$kids =& $field_obj->PDFStructure['Kids'];
					$containers =& $field_obj->container;
					for($i = 0; $i < count($kids); $i++) {
						$subfields[] =& $kids[$i];
					}
				} 
				else {
					$containers = array();
					$subfields[] =& $field_obj->PDFStructure;
					$containers[] =& $field_obj->container;
				}
				/* create a region over the text field */

				for($i = 0; $i < count($subfields); $i++) {
					$field =& $subfields[$i];
					$container =& $containers[$i];
					$col_rect = $field['Rect'];
					$row_height = ($col_rect[3] - $col_rect[1]) / count($text_rows);
					
					$top = $col_rect[3];
					foreach($text_rows as $text) {
						$rect = array($col_rect[0], $top - $row_height, $col_rect[2], $top);
						$top -= $row_height;
						$region = new AxRegion;
					
						/* move the boundary if text is allowed to overflow */
						if($options & AX_OVERFLOW_SIDES) {
							if($options & AX_CENTER_JUSTIFY) {
								$rect[0] -= AX_INFINITE / 2;
								$rect[2] += AX_INFINITE / 2;
							}
							else if($options & AX_RIGHT_JUSTIFY) {
								$rect[0] = - AX_INFINITE;
							}
							else if($options & AX_FULL_JUSTIFY) {
								ax_log_error("cannot use AX_OVERFLOW_SIDES with AX_FULL_JUSTIFY");
							}
							else {
								$rect[2] = AX_INFINITE;
							}
						}
						$region->rectangles = array($rect);	
		
						/* layout the text and add the resulting lines to the page */
						$lines = ax_lay_out_text($text, $region, $style, $options, $padding); 
						ax_add_page_elements($container, $lines);
					}
					ax_remove_widget_annotation($container, $field);
				}

				/* remove the field from the document */
				unset($fields[$field_obj->index]);
				unset($field_objs[$index]);
				$count++;
			}
		}
	}
	ax_end_logging();
	return $count;
}

function ax_set_checkbox(&$doc_obj, $field_name, $value) {
	ax_start_logging();
	$count = 0;	
	if(ax_check_form_field($doc_obj, $field_name)) {
		$fields =& $doc_obj->PDFStructure['Root']['AcroForm']['Fields'];
		$field_objs =& $doc_obj->formFields[$field_name];
		foreach($field_objs as $index => $field_obj) {
			if($field_obj->type == 'Btn' && !($field_obj->flags & 0x00030000)) {		
				$field =& $field_obj->PDFStructure;
				$normal = $field['AP']['N'];
				if($value) {				
					$stream_name = substr($field['V'], 1);
					if(!$stream_name) {
						$keys = array_keys($normal);
						foreach($keys as $key) {
							if($key != 'Off' && $key != 'Type') {
								$stream_name = $key;
							}
						}
					}
				}
				else {
					$stream_name = 'Off';
				}
				if(array_key_exists($stream_name, $normal)) {
					$rect = $field['Rect'];
					$pic = new AxExternalGrahpic;
					$pic->left = $rect[0];
					$pic->bottom = $rect[1];
					$pic->stream =& $normal[$stream_name];
					ax_add_page_element($field_obj->container, $pic);
				}
				/* remove the field from the document */
				ax_remove_widget_annotation($field_obj->container, $field);
				unset($fields[$field_obj->index]);
				unset($field_objs[$index]);
				$count++;
			}
		}
	}
	ax_end_logging();
	return $count;
}

function ax_set_radio_button(&$doc_obj, $field_name, $value) {
	ax_start_logging();
	$count = 0;	
	if(ax_check_form_field($doc_obj, $field_name)) {
		$fields =& $doc_obj->PDFStructure['Root']['AcroForm']['Fields'];
		$field_objs =& $doc_obj->formFields[$field_name];
		foreach($field_objs as $index => $field_obj) {
			if($field_obj->type == 'Btn') {		
				$field =& $field_obj->PDFStructure;
				$kids =& $field['Kids'];
				for($i = 0; $i < count($kids); $i++) {
					$kid =& $kids[$i];

					$normal = $kid['AP']['N'];					
					if(array_key_exists($value, $normal)) {
						$stream_name = $value;
					}
					else {
						$stream_name = 'Off';
					}
					if(array_key_exists($stream_name, $normal)) {
						$rect = $kid['Rect'];
						$pic = new AxExternalGrahpic;
						$pic->left = $rect[0];
						$pic->bottom = $rect[1];
						$pic->stream = $normal[$stream_name];
						$page->elements[] = $pic;
						ax_add_page_element($field_obj->container[$i], $pic);
					}
					/* remove the button from the page */
					ax_remove_widget_annotation($field_obj->container[$i], $kid);
				}
				/* remove the field from the document */
				unset($fields[$field_obj->index]);
				unset($field_objs[$index]);
				$count++;
			}
		}
	}
	ax_end_logging();
	return $count;
}

function ax_reveal_form_fields(&$doc_obj) {
	ax_start_logging();
	if(!$doc_obj->formFields) {
		ax_log_error("document does not contain a form");
		return 0;
	}
	$count = 0;
	$fields =& $doc_obj->PDFStructure['Root']['AcroForm']['Fields'];
	$field_objs =& $doc_obj->formFields[$field_name];
	$style = new AxTextStyle;
	$style->font = ax_get_standard_font('Helvetica');
	$style->fontSize = 4;
	$style->color = 0x990000;
	foreach($doc_obj->formFields as $name => $field_objs) {
		foreach($field_objs as $field_obj) {
			$field =& $field_obj->PDFStructure;
			if($field_obj->type == 'Btn') {
				if(is_array($field_obj->container)) {
					$kids =& $field_obj->PDFStructure['Kids'];
					for($i = 0; $i < count($kids); $i++) {
						$kid =& $kids[$i];
						/* find the value of this radio button */
						$values = array_keys($kid['AP']['N']);
						$value = '';
						
						foreach($values as $v) {
							if($v !== 'Off' && $v !== 'Type') {
								$value = $v;
								break;
							}
						}
						ax_label_form_field($field_obj->container[$i], "$name: $value", $kid['Rect'], $style, AX_LEFT_JUSTIFY | AX_TOP_ALIGN);
					}
				}
				else {
					ax_label_form_field($field_obj->container, $name, $field['Rect'], $style, AX_LEFT_JUSTIFY | AX_TOP_ALIGN);
				}
			}
			else {
				if(is_array($field_obj->container)) {
					$kids =& $field_obj->PDFStructure['Kids'];
					for($i = 0; $i < count($kids); $i++) {
						$kid =& $kids[$i];
						ax_label_form_field($field_obj->container[$i], $name, $kid['Rect'], $style, AX_LEFT_JUSTIFY | AX_BOTTOM_ALIGN);						
					}
				}
				else {
					ax_label_form_field($field_obj->container, $name, $field['Rect'], $style, AX_LEFT_JUSTIFY | AX_BOTTOM_ALIGN);
				}
			}
			$count++;
		}
	}
	ax_end_logging();
	return $count;
} 

function ax_label_form_field(&$container, $label, &$rect, $style, $options) {
	$x = $rect[0] / 72;
	$y = $rect[1] / 72;
	$text_obj = ax_create_text($label, $x, $y, $style, $options);
	$container->elements[] = $text_obj;
}

function ax_remove_form(&$doc_obj) {
	ax_start_logging();
	if($doc_obj->formFields) {
		$root =& $doc_obj->PDFStructure['Root'];	
		unset($root['AcroForm']);
		foreach($doc_obj->formFields as $field_objs) {
			foreach($field_objs as $index => $field_obj) {
				if(is_array($field_obj->container)) {
					$kids =& $field_obj->PDFStructure['Kids'];
					for($i = 0; $i < count($kids); $i++) {
						ax_remove_widget_annotation($field_obj->container[$i], $kids[$i]);
					}
				}
				else {
					ax_remove_widget_annotation($field_obj->container, $field_obj->PDFStructure);
				}
			}		
		}
		unset($doc_obj->formFields);
	}
	ax_end_logging();
	return true;
}

function ax_check_form_field(&$doc_obj, $field_name) {
	if(!$doc_obj->formFields) {
		ax_log_error("document does not contain a form");
		return false;
	}
	if(!array_key_exists($field_name, $doc_obj->formFields)) {
		ax_log_error("no form field with by name \"$field_name\"");
		return false;
	}
	return true;
}

function ax_remove_widget_annotation(&$page, &$field) {
	if(is_array($page->PDFStructure['Annots'])) {
		$annots =& $page->PDFStructure['Annots'];
		$ref = $field['__ref__'];
		while(($i = key($annots)) !== null) {
			if(is_int($i)) {
				if($annots[$i]['__ref__'] == $ref) {
					unset($annots[$i]);
					break;
				}
			}
			next($annots);
		}
		reset($annots);
	}
}

?>