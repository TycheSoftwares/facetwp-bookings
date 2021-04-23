<?php
/**
 * Plugin Name: FacetWP - Booking & Appointment Integration
 * Description: Support for adding a facet for Booking & Appointment plugin.
 * Version: 0.1.0
 * Author: Tyche Softwares
 * Author URI: https://tychesoftwares.com/
 */

defined( 'ABSPATH' ) or exit;

/**
 * Register facet type
 */
add_filter(
	'facetwp_facet_types',
	function( $facet_types ) {
		$facet_types['availability'] = new FacetWP_Facet_Availability();
		return $facet_types;
	}
);


/**
 * Availability facet
 */
class FacetWP_Facet_Availability {

	public $product_ids;
	public $product_to_job_listings; // key = product ID, value = array of job_listing IDs
	public $facet;


	function __construct() {
		$this->label = __( 'Availability', 'fwp' );

		// setup variables
		define( 'FACETWP_BOOKINGS_URL', plugins_url( '', __FILE__ ) );

		// hooks
		add_filter( 'facetwp_store_unfiltered_post_ids', '__return_true' );
		//add_filter( 'facetwp_bookings_filter_posts', array( $this, 'wpjm_products_integration' ) );
	}


	/**
	 * Generate the facet HTML
	 */
	function render( $params ) {
		$value       = $params['selected_values'];
		$dates       = empty( $value ) ? '' : $value[0] . ' - ' . $value[1];
		$quantity    = empty( $value ) ? 1 : $value[2];
		$time        = 'yes' === $params['facet']['time'] ? 'true' : 'false';
		$time_format = empty( $params['facet']['time_format'] ) ? '24hr' : $params['facet']['time_format'];

		$output  = '';
		$output .= '<input type="text" class="facetwp-date" value="' . esc_attr( $dates ) . '" placeholder="' . __( 'Select date range', 'fwp-bookings' ) . '" data-enable-time="' . $time . '" data-time-format="' . $time_format . '"  />';
		$output .= '<input type="number" class="facetwp-quantity" value="' . esc_attr( $quantity ) . '" min="0" placeholder="' . __( 'Quantity', 'fwp-bookings' ) . '" style="display:none" />';
		return $output;
	}


	/**
	 * Filter the query based on selected values
	 */
	function filter_posts( $params ) {
		global $wpdb;

		$output      = array();
		$facet       = $params['facet'];
		$values      = $params['selected_values'];
		$behavior    = empty( $facet['behavior'] ) ? 'default' : $facet['behavior'];
		$this->facet = $facet;

		$start_date = $values[0];
		$end_date   = $values[1];
		$quantity   = empty( $values[2] ) ? 1 : (int) $values[2];

		// Get available bookings
		if ( $this->is_valid_date( $start_date ) && $this->is_valid_date( $end_date ) ) {
			$output = $this->get_available_bookings( $start_date, $end_date, $quantity, $behavior );
		}

		return apply_filters( 'facetwp_bookings_filter_posts', $output );
	}


	/**
	 * Get all available booking products
	 *
	 * @param string $start_date YYYY-MM-DD format.
	 * @param string $end_date YYYY-MM-DD format.
	 * @param int    $quantity Number of people to book.
	 * @param string $behavior Whether to return exact matches.
	 * @return array Available post IDs
	 */
	public function get_available_bookings( $start_date, $end_date, $quantity = 1, $behavior = 'default' ) {

		if ( $start_date === $end_date ) {
			$is_global_holiday = bkap_check_holiday( $start_date, $end_date );

			if ( $is_global_holiday ) {
				return array();
			}
		}

		$bookable_products = bkap_common::get_woocommerce_product_list( false );

		$filtered_products = array();

		foreach ( $bookable_products as $pro_key => $pro_value ) {

			$product_id   = $pro_value['1'];
			$view_product = bkap_check_booking_available( $product_id, $start_date, $end_date );

			if ( $view_product ) {
				array_push( $filtered_products, $product_id );
			}
		}

		return $filtered_products;
	}

	/**
	 * Validate date input
	 *
	 * @requires PHP 5.3+
	 */
	public function is_valid_date( $date ) {
		if ( empty( $date ) ) {
			return false;
		} elseif ( 10 === strlen( $date ) ) {
			$d = DateTime::createFromFormat( 'Y-m-d', $date );
			return $d && $d->format( 'Y-m-d' ) === $date;
		} elseif ( 16 === strlen( $date ) ) {
			$d = DateTime::createFromFormat( 'Y-m-d H:i', $date );
			return $d && $d->format( 'Y-m-d H:i' ) === $date;
		}

		return false;
	}


	/**
	 * Output any front-end scripts
	 */
	public function front_scripts() {
		FWP()->display->assets['moment.js']           = FACETWP_BOOKINGS_URL . '/assets/vendor/daterangepicker/moment.min.js';
		FWP()->display->assets['daterangepicker.js']  = FACETWP_BOOKINGS_URL . '/assets/vendor/daterangepicker/daterangepicker.min.js';
		FWP()->display->assets['daterangepicker.css'] = FACETWP_BOOKINGS_URL . '/assets/vendor/daterangepicker/daterangepicker.css';
		FWP()->display->assets['bootstrap.css']       = FACETWP_BOOKINGS_URL . '/assets/vendor/daterangepicker/bootstrap.css';
		?>
<script>
(function($) {
	FWP.hooks.addAction('facetwp/refresh/availability', function($this, facet_name) {
		var $input = $this.find('.facetwp-date');
		var date = $input.val() || '';
		var dates = ('' !== date) ? date.split(' - ') : '';
		var quantity = $this.find('.facetwp-quantity').val() || 1;
		FWP.facets[facet_name] = ('' != date) ? [dates[0], dates[1], quantity] : [];
		if (FWP.loaded) {
			//$input.data('daterangepicker').remove(); // cleanup the datepicker
		}
	});
	FWP.hooks.addFilter('facetwp/selections/availability', function(output, params) {
		return params.selected_values[0] + ' - ' + params.selected_values[1];
	});
	$(document).on('facetwp-loaded', function() {
		$('.facetwp-type-availability .facetwp-date:not(.ready)').each(function() {
			var $this = $(this);
			var isTimeEnabled = $this.attr('data-enable-time') === 'true';
			var is24Hour = $this.attr('data-time-format') === '24hr';
			var dateFormat = isTimeEnabled ? 'YYYY-MM-DD HH:mm' : 'YYYY-MM-DD';

			$this.daterangepicker({
				autoUpdateInput: false,
				minDate: moment().startOf('hour'),
				timePicker: isTimeEnabled,
				timePicker24Hour: is24Hour,
				timePickerIncrement: 5,
				locale: {
					cancelLabel: 'Clear',
					format: dateFormat
				}
			});
			$this.on('apply.daterangepicker', function(ev, picker) {
				var startDate = moment(picker.startDate).format(dateFormat);
				var endDate = moment(picker.endDate).format(dateFormat);
				$(this).val(startDate + ' - ' + endDate);
				FWP.autoload();
			});
			$this.on('cancel.daterangepicker', function(ev, picker) {
				$(this).val('');
				FWP.autoload();
			});
		});
	});
})(jQuery);
</script>
		<?php
	}


	/**
	 * Output admin settings HTML
	 */
	function settings_html() {
		?>
		<!-- <div class="facetwp-row">
			<div>
				<?php _e( 'Use time?', 'fwp' ); ?>:
				<div class="facetwp-tooltip">
					<span class="icon-question">?</span>
					<div class="facetwp-tooltip-content"><?php _e( 'Support time based bookings?', 'fwp' ); ?></div>
				</div>
			</div>
			<div>
				<select class="facet-time">
					<option value="no"><?php _e( 'No', 'fwp' ); ?></option>
					<option value="yes"><?php _e( 'Yes', 'fwp' ); ?></option>
				</select>
			</div>
		</div>
		<div class="facetwp-row">
			<div>
				<?php _e( 'Behavior', 'fwp' ); ?>:
				<div class="facetwp-tooltip">
					<span class="icon-question">?</span>
					<div class="facetwp-tooltip-content"><?php _e( 'Set how the range is handled.', 'fwp' ); ?></div>
				</div>
			</div>
			<div>
				<select class="facet-behavior">
					<option value="default"><?php _e( 'Any results within range', 'fwp' ); ?></option>
					<option value="exact"><?php _e( 'Results that match the exact range', 'fwp' ); ?></option>
				</select>
			</div>
		</div>
		<div class="facetwp-row" v-show="facet.time == 'yes'">
			<div>
				<?php _e( 'Time Format', 'fwp' ); ?>:
			</div>
			<div>
				<select class="facet-time-format">
					<option value="12hr"><?php _e( 'AM / PM', 'fwp' ); ?></option>
					<option value="24hr"><?php _e( '24 Hour', 'fwp' ); ?></option>
				</select>
			</div>
		</div> -->
		<?php
	}
}
