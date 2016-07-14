<?php

class CHIEF_SFC_Form {

	public $form_id;
	public $source;
	public $name;
	public $fields;
	public $list_url;
	public $url;

	public function __construct( $form_id = 0, $source = '' ) {
		$this->form_id = (int) $form_id;
		$this->source  = sanitize_key( $source );

		$this->list_url = admin_url( 'admin.php?page=chief-sfc-captures' );

		$this->url = esc_url_raw( add_query_arg( array(
			'form'   => $this->form_id,
			'source' => $this->source
		), $this->list_url ) );

		$form = $this->get_form();
		$this->name   = $form['name'];
		$this->fields = $form['fields'];

		$this->values = $this->get_values();

	}

	/**
	 * Add actions. This runs during the load-{slug} hook, right before
	 * HTTP headers are sent.
	 */
	public function add_actions() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_footer_scripts' ) );
	}

	public function enqueue_footer_scripts() {
		wp_enqueue_script( 'chief-sfc-scripts', CHIEF_SFC_URL . 'js/scripts.js', array(), CHIEF_SFC_VERSION, false );
	}

	private function get_form() {
		$form = false;
		switch( $this->source ) {
			case 'frm' :
				if ( is_callable( array( 'FrmForm', 'getOne' ) ) )
					$form = FrmForm::getOne( $this->form_id );
				break;
			case 'cf7' :
				if ( is_callable( array( 'WPCF7_ContactForm', 'get_instance' ) ) )
					$form = WPCF7_ContactForm::get_instance( $this->form_id );
				break;
		}
		if ( is_callable( array( $this, 'normalize_' . $this->source . '_form' ) ) )
			$form = call_user_func( array( $this, 'normalize_' . $this->source . '_form' ), $form );

		return $form;
	}

	public function unique_key() {
		return $this->form_id . '_' . $this->source;
	}

	public function get_disable_url() {
		return esc_url_raw( add_query_arg( array(
			'chief_sfc_action'   => 'disable',
			'_chief_sfc_disable' => wp_create_nonce( 'chief-sfc-disable' )
		), $this->url ) );
	}

	/**
	 * Get fields and pertinent info from a Formidable form.
	 */
	private function normalize_frm_form( $form ) {
		$fields = array();
		$frm_fields = FrmField::get_all_for_form( $this->form_id );

		foreach( $frm_fields as $field ) {
			$fields[] = array(
				'name'  => 'item_meta[' . $field->id . ']',
				'label' => $field->name ? $field->name : '(no label)'
			);
		}

		$form = array(
			'name'   => sanitize_text_field( $form->name ),
			'fields' => $fields
		);
		return $form;
	}

	/**
	 * Get fields and pertinent info from a Contact Form 7 form.
	 */
	private function normalize_cf7_form( $form ) {
		$fields = array();

		if ( is_callable( array( 'WPCF7_ShortcodeManager', 'get_instance' ) ) ) {
			$manager = WPCF7_ShortcodeManager::get_instance();
			$scanned_fields = $manager->scan_shortcode( $form->prop( 'form' ) );
			foreach( $scanned_fields as $field ) {
				$fields[] = array(
					'name'  => $field['name'],
					'label' => $field['name']
				);
			}
		}

		$form = array(
			'name'   => sanitize_text_field( $form->title() ),
			'fields' => $fields
		);
		return $form;
	}

	/**
	 * Get values for this form from wp_options.
	 */
	public function get_values() {
		$values = array();

		$all_forms = get_option( 'chief_sfc_captures', array() );
		$key = $this->unique_key();

		// nothing saved for this form yet
		if ( !isset( $all_forms[$key] ) )
			$all_forms[$key] = array();

		// normalize values
		$values = wp_parse_args( $all_forms[$key], array(
			'object' => '',
			'fields' => array()
		) );

		return $values;
	}

	/**
	 * Get pretty name of source.
	 */
	public function source_label() {
		if ( $this->source === 'frm' )
			return 'Formidable';
		if ( $this->source === 'cf7' )
			return 'Contact Form 7';
		if ( $this->source === 'grv' )
			return 'Gravity Forms';
		return '';
	}

	/**
	 * Get whether or not the current form is actively syncing with Salesforce.
	 * True for enabled, false for disabled.
	 */
	public function is_enabled() {
		$values = array_filter( $this->values ); // do any values exist
		return (bool) $values;
	}

	/**
	 * Get the status in human-readable form.
	 */
	public function get_status_label() {
		ob_start();
		if ( $this->is_enabled() ) {
			$object = isset( $this->values['object'] ) ? $this->values['object'] : '';
			?><span class="enabled">
				Saving to Salesforce
				<?php if ( $object ) { ?>
					(as <?php echo $object; ?>)
				<?php } ?>
			</span><?php
		} else {
			?><span class="disabled">Not saving to Salesforce</span><?php
		}
		return ob_get_clean();
	}

	public function display() {

		// debug:
		// echo '<pre>';
		// print_r( $this->values );
		// echo '</pre>';

		?>
		<h2>
			<?php echo $this->name; ?>
			<a class="page-title-action" href="<?php echo esc_url( $this->list_url ); ?>">View All</a>
		</h2>
		<?php if ( !empty( $_GET['updated'] ) && $_GET['updated'] === 'true' ) { ?>
			<div class="updated notice is-dismissible"><p>Form saved successfully.</p></div>
		<?php } ?>
		<?php if ( !empty( $_GET['skipped'] ) && $_GET['skipped'] === 'save' ) { ?>
			<div class="error notice is-dismissible"><p>Form could not be saved. Please try again.</p></div>
		<?php } ?>
		<div class="chief-sfc-form-page">
			<form class="chief-sfc-form" action="<?php echo esc_url( $this->url ); ?>" method="post">
				<input type="hidden" name="chief_sfc_action" value="save" />
				<?php wp_nonce_field( 'chief-sfc-form', '_chief_sfc_form' ); ?>
				<div class="metabox-holder">
					<div id="postbox-container-1" class="postbox-container">
						<div class="meta-box-sortables">
							<div class="postbox">
								<h3 class="hndle ui-sortable-handle">
									<span>Save to Salesforce</span>
								</h3>
								<div class="inside">
									<table class="form-table">
										<tr class="object-row">
											<th>Object</th>
											<td>
												<select
													id="chief-sfc-object"
													name="object"
													data-form="<?php echo esc_attr( $this->form_id ); ?>"
													data-source="<?php echo esc_attr( $this->source ); ?>">
													<option value="">&mdash; Select object &mdash;</option>
													<?php foreach( $this->get_objects() as $object ) { ?>
														<option
															value="<?php echo esc_attr( $object ); ?>"
															<?php selected( $this->values['object'], $object ); ?>>
															<?php echo esc_html( $object ); ?>
														</option>
													<?php } ?>
												</select>
												<span class="spinner object-spinner"></span>
												<p class="howto">Select the Salesforce object in which to save this form's submissions.</p>
											</td>
										</tr>
										<?php if ( $this->values['object'] ) {
											$this->view_field_matching( $this->values['object'] );
										} ?>
									</table>
								</div>
							</div>
						</div>
					</div>
					<div id="postbox-container-2" class="postbox-container">
						<div class="meta-box-sortables">
							<div class="postbox">
								<h3 class="hndle ui-sortable-handle">
									<span>Status</span>
								</h3>
								<div class="inside">
									<p>Form: <strong><?php echo $this->name; ?></strong></p>
									<p>Source: <strong><?php echo $this->source_label(); ?></strong></p>
									<p>Status: <strong><?php echo $this->get_status_label(); ?></strong></p>
								</div>
								<div class="submit-container">
									<?php if ( $this->is_enabled() ) { ?>
										<a class="submitdisable" href="<?php echo esc_url( $this->get_disable_url() ); ?>">Disable</a>
									<?php } ?>
									<?php submit_button( 'Save', 'primary', 'submit', false ); ?>
									<div class="spinner submit-spinner"></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</form>
		</div><!-- .chief-sfc-form-page --><?php

	}

	public function get_objects() {
		return apply_filters( 'chief_sfc_objects', array(
			'Contact',
			'Lead'
		) );
	}

	public function get_object_fields( $object = '' ) {
		if ( !in_array( $object, $this->get_objects() ) )
			return array();

		$object = sanitize_text_field( $object );

		$response = CHIEF_SFC_Remote::get( 'sobjects/' . $object . '/describe' );

		// @todo i think an error is happening here when we need to refresh the access token

		if ( is_wp_error( $response ) )
			return array();

		if ( !is_object( $response ) )
			return array();

		if ( !isset( $response->fields ) )
			return array();

		$fields = array();
		foreach ( $response->fields as $fieldobj ) {
			if( $fieldobj->updateable ) {
				$fields[] = array(
					'name'  => $fieldobj->name,
					'label' => $fieldobj->label
				);
			}
		}

		return $fields;
	}

	public function view_field_matching( $object ) {
		$sf_fields = $this->get_object_fields( $object );
		if ( $sf_fields ) {
			foreach( $sf_fields as $sf_field ) {
				$sf_field = wp_parse_args( $sf_field, array(
					'name'  => '',
					'label' => ''
				) ); ?>
				<tr>
					<th><?php echo esc_html( $sf_field['label'] ); ?></th>
					<td>
						<select name="field[<?php echo esc_attr( $sf_field['name'] ); ?>]">
							<option value="">&mdash; Select field &mdash;</option>
							<?php foreach( $this->fields as $field ) {
								$field = wp_parse_args( $field, array(
									'name' => '',
									'label' => ''
								) ); ?>
								<option
									value="<?php echo esc_attr( $field['name'] ); ?>"
									<?php selected( $field['name'], $this->values['fields'][$sf_field['name']]); ?>>
									<?php echo esc_html( $field['label'] ); ?>
								</option>
							<?php } ?>
						</select>
					</td>
				</tr>
			<?php }
		}
	}

	/**
	 * Check whether we need to save or disable the current form capture.
	 *
	 * This runs in the load-{slug} hook, so it's already limited to only the Captures list/edit pages.
	 */
	public function maybe_update() {


		if ( !$nonce )
			return;

		if ( wp_verify_nonce( $nonce, 'chief-sfc-form' ) )
			$this->save();

		if ( wp_verify_nonce( $nonce, 'chief-sfc-disable' ) )
			$this->disable();

	}

	/**
	 * Sanitize the form capture data and save to an option.
	 */
	public function save() {

		// don't do a thing unless the nonce passes
		$nonce = isset( $_POST['_chief_sfc_form'] ) ? $_POST['_chief_sfc_form'] : false;
		if ( !$nonce || !wp_verify_nonce( $nonce, 'chief-sfc-form' ) )
			$this->fail_update( 'save' );

		// sanitize
		$sanitized_object = isset( $_POST['object'] ) ? sanitize_text_field( $_POST['object'] ) : '';
		$fields = isset( $_POST['field'] ) ? $_POST['field'] : array();
		$sanitized_fields = array();
		foreach( $fields as $key => $field ) {
			$new_key   = sanitize_text_field( $key );
			$new_field = sanitize_text_field( $field );
			$sanitized_fields[$new_key] = $new_field;
		}

		// save as option
		$option = get_option( 'chief_sfc_captures', array() );
		$key    = $this->unique_key();
		$option[$key] = array(
			'object' => $sanitized_object,
			'fields' => $sanitized_fields
		);
		update_option( 'chief_sfc_captures', $option );

		// redirect
		$url = esc_url_raw( add_query_arg( 'updated', 'true', $this->url ) );
		wp_redirect( $url );
		exit;

	}

	/**
	 * Disable the current form.
	 */
	public function disable() {

		// don't do a thing unless the nonce passes
		$nonce = isset( $_GET['_chief_sfc_disable'] ) ? $_GET['_chief_sfc_disable'] : false;
		if ( !$nonce || !wp_verify_nonce( $nonce, 'chief-sfc-disable' ) )
			$this->fail_update( 'disable' );

		$option = get_option( 'chief_sfc_captures', array() );
		$key    = $this->unique_key();

		unset( $option[$key] );

		update_option( 'chief_sfc_captures', $option );

		// redirect to success/failure
		$url = esc_url_raw( add_query_arg( 'disabled', 'true', $this->list_url ) );
		wp_redirect( $url );
		exit;

	}

	/**
	 * When an updated is attempted but it doesn't pass the nonce check, redirect and provide
	 * an error message.
	 */
	public function fail_update( $context = 'save' ) {
		if ( !in_array( $context, array( 'save', 'disable'  ) ) )
			return;

		$url = ( $context === 'save' ) ? $this->url : $this->list_url;
		$url = esc_url_raw( add_query_arg( 'skipped', $context, $url ) );
		wp_redirect( $url );
		exit;

	}

}