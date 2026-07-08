<?php
/**
 * Keyword opportunity scoring.
 *
 * Deterministic, explainable 0-100 score: impressions volume, CTR gap vs the
 * expected CTR for the ranking position, position sweet spot, presence
 * deficit, intent boost, branded dampening. The component breakdown is kept
 * so the UI can explain a score.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Scorer {

	/**
	 * Score a keyword row.
	 *
	 * @param array $ctx { impressions, ctr (0-1), position, presence_status, labels }.
	 * @return array { score, level, breakdown }
	 */
	public static function score( array $ctx ) {
		$impressions = isset( $ctx['impressions'] ) ? max( 0, (int) $ctx['impressions'] ) : 0;
		$ctr         = isset( $ctx['ctr'] ) ? max( 0.0, (float) $ctx['ctr'] ) : 0.0;
		$position    = isset( $ctx['position'] ) ? (float) $ctx['position'] : 0.0;
		$presence    = isset( $ctx['presence_status'] ) ? (string) $ctx['presence_status'] : 'unknown';
		$labels      = isset( $ctx['labels'] ) ? (array) $ctx['labels'] : array();

		$weights = apply_filters(
			'convertrack_keywords_score_weights',
			array(
				'impressions' => 30,
				'ctr_gap'     => 25,
				'position'    => 25,
				'presence'    => 15,
				'intent'      => 5,
				'branded'     => 0.5,
			)
		);

		// Volume: log-scaled, saturating around 1,000 impressions.
		$i = $weights['impressions'] * min( 1, log10( $impressions + 1 ) / 3 );

		// CTR gap vs expectation for the position band, discounted for rows
		// with too few impressions to trust their CTR.
		$expected   = self::expected_ctr( $position );
		$gap        = $expected > 0 ? max( 0, $expected - $ctr ) / $expected : 0;
		$confidence = min( 1, $impressions / max( 1, (int) Keywords_Settings::get( 'min_impressions', 10 ) ) );
		$c          = $weights['ctr_gap'] * min( 1, $gap ) * $confidence;

		// Positions 4-20 are the sweet spot where content work moves the needle.
		if ( $position >= 4 && $position <= 10 ) {
			$p = $weights['position'];
		} elseif ( $position >= 11 && $position <= 20 ) {
			$p = $weights['position'] * 0.8;
		} elseif ( $position >= 21 && $position <= 30 ) {
			$p = $weights['position'] * 0.48;
		} elseif ( $position >= 31 && $position <= 50 ) {
			$p = $weights['position'] * 0.24;
		} elseif ( $position >= 1 && $position < 4 ) {
			$p = $weights['position'] * 0.16;
		} else {
			$p = $weights['position'] * 0.08;
		}

		$deficits = array(
			'missing'           => 1.0,
			'needs_improvement' => 0.67,
			'partial'           => 0.47,
			'overused'          => 0.33,
			'unknown'           => 0.33,
			'present'           => 0.0,
		);
		$d = $weights['presence'] * ( isset( $deficits[ $presence ] ) ? $deficits[ $presence ] : 0.33 );

		if ( array_intersect( array( 'transactional', 'commercial', 'location' ), $labels ) ) {
			$b = $weights['intent'];
		} elseif ( array_intersect( array( 'product', 'service' ), $labels ) ) {
			$b = $weights['intent'] * 0.6;
		} elseif ( in_array( 'question', $labels, true ) ) {
			$b = $weights['intent'] * 0.4;
		} else {
			$b = 0;
		}

		$m     = in_array( 'branded', $labels, true ) ? (float) $weights['branded'] : 1.0;
		$score = (int) round( max( 0, min( 100, ( $i + $c + $p + $d + $b ) * $m ) ) );

		if ( $score >= 70 ) {
			$level = 'high';
		} elseif ( $score >= 40 ) {
			$level = 'medium';
		} else {
			$level = 'low';
		}
		if ( $position >= 1 && $position <= 3 && 'present' === $presence ) {
			$level = 'optimized';
		}

		return array(
			'score'     => $score,
			'level'     => $level,
			'breakdown' => array(
				'i' => round( $i, 1 ),
				'c' => round( $c, 1 ),
				'p' => round( $p, 1 ),
				'd' => round( $d, 1 ),
				'b' => round( $b, 1 ),
				'm' => $m,
			),
		);
	}

	/**
	 * Expected CTR (0-1 fraction) for a ranking position.
	 *
	 * @param float $position Average position.
	 * @return float
	 */
	public static function expected_ctr( $position ) {
		$position = (float) $position;

		if ( $position >= 1 && $position < 1.5 ) {
			$expected = 0.28;
		} elseif ( $position < 2.5 && $position >= 1 ) {
			$expected = 0.15;
		} elseif ( $position < 3.5 && $position >= 1 ) {
			$expected = 0.10;
		} elseif ( $position <= 5 && $position >= 1 ) {
			$expected = 0.07;
		} elseif ( $position <= 10 && $position >= 1 ) {
			$expected = 0.04;
		} elseif ( $position <= 20 && $position >= 1 ) {
			$expected = 0.015;
		} elseif ( $position <= 50 && $position >= 1 ) {
			$expected = 0.007;
		} else {
			$expected = 0.003;
		}

		return (float) apply_filters( 'convertrack_keywords_expected_ctr', $expected, $position );
	}
}
