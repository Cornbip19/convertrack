<?php
/**
 * Filterable word lists driving keyword classification.
 *
 * English only by default; every list runs through a filter so other
 * languages can be added without touching the plugin. VERSION feeds the
 * analysis staleness hash — bump it whenever shipped lists change.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Word_Lists {

	const VERSION = 1;

	/**
	 * Memoized filtered lists.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Question starter words (matched against the first query token).
	 *
	 * @return array
	 */
	public static function question_starters() {
		return self::filtered( 'question', array( 'who', 'what', 'when', 'where', 'why', 'how', 'which', 'can', 'could', 'does', 'do', 'did', 'is', 'are', 'was', 'were', 'should', 'would', 'will', 'am' ) );
	}

	/**
	 * Question phrases (matched anywhere in the query).
	 *
	 * @return array
	 */
	public static function question_phrases() {
		return self::filtered( 'question_phrases', array( 'how to', 'how do', 'how does', 'how much', 'how long', 'what is', 'what are', 'can i', 'can you', 'is it', 'do i', 'should i' ) );
	}

	/**
	 * Transactional intent terms.
	 *
	 * @return array
	 */
	public static function transactional() {
		return self::filtered( 'transactional', array( 'buy', 'purchase', 'order', 'hire', 'book', 'booking', 'quote', 'quotes', 'estimate', 'pricing', 'price', 'prices', 'cost', 'costs', 'fee', 'fees', 'cheap', 'affordable', 'discount', 'coupon', 'deal', 'deals', 'for sale', 'sale', 'near me', 'delivery', 'shipping', 'appointment', 'schedule', 'rent', 'rental', 'subscribe', 'sign up', 'download', 'install', 'replace', 'repair', 'fix', 'emergency' ) );
	}

	/**
	 * Commercial-investigation intent terms.
	 *
	 * @return array
	 */
	public static function commercial() {
		return self::filtered( 'commercial', array( 'best', 'top', 'review', 'reviews', 'compare', 'comparison', 'vs', 'versus', 'alternative', 'alternatives', 'cheapest', 'top rated', 'rated', 'ranking', 'recommended', 'worth it', 'pros and cons', 'difference between' ) );
	}

	/**
	 * Informational intent terms.
	 *
	 * @return array
	 */
	public static function informational() {
		return self::filtered( 'informational', array( 'how to', 'guide', 'tutorial', 'what is', 'what are', 'examples', 'example', 'ideas', 'tips', 'learn', 'meaning', 'definition', 'benefits', 'checklist', 'template', 'ways to', 'diy', 'why', 'history of', 'types of' ) );
	}

	/**
	 * Proximity phrases that signal local intent without naming a place.
	 * No city gazetteer ships with the plugin — location labeling combines
	 * these with the user-configured location terms.
	 *
	 * @return array
	 */
	public static function near_me() {
		return self::filtered( 'near_me', array( 'near me', 'nearby', 'close to me', 'in my area', 'local', 'closest' ) );
	}

	/**
	 * Terms that, combined with a brand match, signal navigational intent.
	 *
	 * @return array
	 */
	public static function navigational_intent() {
		return self::filtered( 'navigational_intent', array( 'login', 'log in', 'sign in', 'account', 'contact', 'phone', 'number', 'email', 'address', 'hours', 'opening', 'directions', 'location', 'locations', 'careers', 'jobs', 'support', 'reviews' ) );
	}

	/**
	 * Return a list through its filter, memoized per request.
	 *
	 * @param string $name  List name (filter suffix).
	 * @param array  $terms Shipped terms.
	 * @return array
	 */
	private static function filtered( $name, array $terms ) {
		if ( ! isset( self::$cache[ $name ] ) ) {
			self::$cache[ $name ] = array_values( array_filter( array_map( 'strval', (array) apply_filters( "convertrack_keywords_{$name}_terms", $terms ) ), 'strlen' ) );
		}
		return self::$cache[ $name ];
	}
}
