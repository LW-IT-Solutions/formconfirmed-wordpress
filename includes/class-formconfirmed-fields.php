<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Maps server-owned field definitions; labels and submitted field names cannot set roles. */
class Formconfirmed_Fields {
	const OPTION = 'formconfirmed_field_roles';

	public static function eligible( $f ) {
		if ( ! is_array( $f ) || ! is_string( $f['type'] ?? null ) || ! is_scalar( $f['id'] ?? null ) ) { return false; }
		$type = strtolower( $f['type'] );
		$types = array( 'text','email','name','textarea','tel','phone','url','number','date','select','checkbox','radio','address','input_text','input_email','input_name','input_textarea','input_number','input_url','input_date','select_country','input_checkbox','input_radio' );
		$key = str_replace( '-', '_', is_string( $f['key'] ?? null ) ? $f['key'] : (string) $f['id'] );
		return in_array( $type, $types, true ) && ! preg_match( '/pass(word|wort|phrase)?|secret|token|nonce|authorization|api_?key|credit_?card|card_?number|cvv/i', $key );
	}

	/** Metadata only, read from the installed form plugin; never submitted values. */
	public static function definitions( $adapter, $form_id ) {
		$id = (int) $form_id; $items = array();
		if ( $id < 1 || ! in_array( (string) $id, array_map( 'strval', array_keys( Formconfirmed_Core::forms_of( $adapter ) ) ), true ) ) { return $items; }
		if ( 'cf7' === $adapter && class_exists( 'WPCF7_ContactForm' ) ) {
			$form = WPCF7_ContactForm::get_instance( $id );
			foreach ( $form ? $form->scan_form_tags() : array() as $f ) { if ( ! empty( $f->name ) ) { $items[] = array( 'id'=>$f->name, 'type'=>$f->basetype, 'label'=>$f->name, 'autocomplete'=>$f->get_option('autocomplete','',true) ); } }
		} elseif ( 'wpforms' === $adapter ) {
			$form = get_post( $id ); $data = $form ? json_decode( $form->post_content, true ) : null;
			foreach ( is_array( $data ) ? (array) ( $data['fields'] ?? array() ) : array() as $fid=>$f ) { if ( is_array($f) ) { $items[] = array('id'=>$fid,'type'=>$f['type']??'','label'=>$f['label']??(string)$fid); } }
		} elseif ( 'gravityforms' === $adapter && class_exists('GFAPI') ) {
			$data = GFAPI::get_form($id);
			foreach ( is_array($data) ? (array)($data['fields']??array()) : array() as $f ) { if(is_object($f))$items[]=array('id'=>$f->id,'type'=>method_exists($f,'get_input_type')?$f->get_input_type():$f->type,'label'=>$f->label); }
		} elseif ( 'fluentform' === $adapter && function_exists('wpFluent') ) {
			$data=wpFluent()->table('fluentform_forms')->where('id',$id)->first();
			$fields=$data&&is_string($data->form_fields??null)?json_decode($data->form_fields,true):array();
			self::fluent_definitions( is_array($fields)?(array)($fields['fields']??array()):array(), $items, 0 );
		} elseif ( 'formidable' === $adapter && class_exists('FrmField') ) {
			foreach((array)FrmField::get_all_for_form($id) as $f){if(is_object($f))$items[]=array('id'=>$f->id,'key'=>$f->field_key,'type'=>$f->type,'label'=>$f->name);}
		}
		return array_slice($items,0,200);
	}

	private static function fluent_definitions( $fields, &$items, $depth ) {
		if($depth>12||count($items)>=200)return;
		foreach(array_slice((array)$fields,0,200) as $f){
			if(!is_array($f))continue;
			if(is_string($f['attributes']['name']??null))$items[]=array('id'=>$f['attributes']['name'],'type'=>$f['element']??'','label'=>$f['settings']['label']??$f['attributes']['name'],'autocomplete'=>$f['attributes']['autocomplete']??'');
			foreach((array)($f['columns']??array()) as $column)if(is_array($column))self::fluent_definitions($column['fields']??array(),$items,$depth+1);
			if(count($items)>=200)return;
		}
	}

	public static function saved_roles( $adapter, $form_id ) {
		$all=get_option(self::OPTION,array());$roles=is_array($all)?($all[$adapter][(string)(int)$form_id]??array()):array();
		$out=array();foreach(array_slice(is_array($roles)?$roles:array(),0,200,true) as $id=>$role)if(is_string($role)&&in_array($role,array('name','email','content','ignore'),true))$out[(string)$id]=$role;
		return $out;
	}

	/** Validates every posted ID and type against the current server-owned schema. */
	public static function save_roles( $adapter, $form_id, $posted ) {
		if(!in_array($adapter,Formconfirmed_Core::PER_FORM,true))return false;
		$definitions=self::definitions($adapter,$form_id);if(!$definitions)return false;
		$clean=array();$posted=is_array($posted)?$posted:array();
		foreach($definitions as $f){$id=(string)$f['id'];$role=$posted[$id]??'';if(self::eligible($f)&&is_string($role)&&in_array($role,array('name','email','content','ignore'),true))$clean[$id]=$role;}
		$all=get_option(self::OPTION,array());$all=is_array($all)?$all:array();$all[$adapter][(string)(int)$form_id]=$clean;
		update_option(self::OPTION,$all,false);return true;
	}

	public static function map( $items, $adapter = '', $form_id = 0 ) {
		$out = array( 'pairs' => array(), 'name' => '', 'email' => '', 'content' => '' );
		$roles = apply_filters( 'formconfirmed_field_roles', self::saved_roles( $adapter, $form_id ), $adapter, (int) $form_id );
		$roles = is_array( $roles ) ? $roles : array();
		$names = array(); $messages = array();
		foreach ( array_slice( (array) $items, 0, 200 ) as $f ) {
			if ( ! self::eligible( $f ) ) { continue; }
			$type = strtolower( $f['type'] );
			$aliases = array( 'input_text' => 'text', 'input_email' => 'email', 'input_name' => 'name', 'input_textarea' => 'textarea', 'phone' => 'tel', 'input_number' => 'number', 'input_url' => 'url', 'input_date' => 'date', 'select' => 'select', 'select_country' => 'select', 'input_checkbox' => 'checkbox', 'input_radio' => 'radio' );
			$type = $aliases[$type] ?? $type;
			if ( ! in_array( $type, array( 'text', 'email', 'name', 'textarea', 'tel', 'url', 'number', 'date', 'select', 'checkbox', 'radio', 'address' ), true ) ) { continue; }
			$id = (string) $f['id'];
			$key = strtolower( str_replace( '-', '_', is_string( $f['key'] ?? null ) ? $f['key'] : $id ) );
			if ( preg_match( '/pass(word|wort|phrase)?|secret|token|nonce|authorization|api_?key|credit_?card|card_?number|cvv/i', $key ) ) { continue; }
			$role = '';
			if ( 'name' === $type ) { $role = 'name'; }
			elseif ( 'email' === $type ) { $role = 'email'; }
			elseif ( 'textarea' === $type ) { $role = 'content'; }
			elseif ( 'text' === $type ) {
				$ac = is_string( $f['autocomplete'] ?? null ) ? preg_split( '/\s+/', strtolower( $f['autocomplete'] ) ) : array();
				if ( array_intersect( $ac, array( 'name', 'given-name', 'family-name', 'additional-name' ) ) || in_array( $key, array( 'name', 'your_name', 'full_name', 'fullname', 'first_name', 'last_name', 'given_name', 'family_name' ), true ) ) { $role = 'name'; }
				elseif ( in_array( $key, array( 'message', 'your_message', 'comment', 'comments' ), true ) ) { $role = 'content'; }
			}
			if ( isset( $roles[$id] ) && is_string( $roles[$id] ) && in_array( $roles[$id], array( 'name', 'email', 'content', 'ignore' ), true ) ) { $role = $roles[$id]; }
			if ( 'ignore' === $role ) { continue; }
			$v = $f['value'] ?? '';
			if ( is_array( $v ) ) {
				$parts = array();
				foreach ( array_slice( $v, 0, 30, true ) as $part => $value ) {
					if ( ! is_scalar( $value ) ) { continue; }
					if ( 'name' === $role && ! is_int( $part ) && ! in_array( $part, array( 'first', 'middle', 'last', 'first_name', 'middle_name', 'last_name', 'given', 'family', 'prefix', 'suffix' ), true ) ) { continue; }
					$parts[] = mb_substr( (string) $value, 0, 5000 );
				}
				$v = implode( ' ', $parts );
			}
			if ( ! is_scalar( $v ) ) { continue; }
			$v = mb_substr( (string) $v, 0, 5000 );
			if ( '' === trim( $v ) ) { continue; }
			$label = is_string( $f['label'] ?? null ) ? $f['label'] : $id;
			$out['pairs'][] = array( mb_substr( $label, 0, 100 ), $v );
			if ( 'name' === $role ) { $names[] = mb_substr( $v, 0, 200 ); }
			elseif ( 'content' === $role ) { $messages[] = mb_substr( $v, 0, 2000 ); }
			elseif ( 'email' === $role && '' === $out['email'] && is_email( trim( $v ) ) ) { $out['email'] = trim( $v ); }
		}
		$out['name'] = mb_substr( implode( ' ', $names ), 0, 200 );
		$out['content'] = mb_substr( implode( "\n\n", $messages ), 0, 2000 );
		return $out;
	}
}
