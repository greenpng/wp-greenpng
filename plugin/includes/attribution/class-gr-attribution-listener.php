<?php
/**
 * Attribution listener (docs/02 §4, ADR-0005): the one front-end
 * checkpoint that turns a marketing entry into attribution state. The
 * consent gate is absolute — without marketing consent no cookie is
 * written and no touchpoint row exists; the session row still gets its
 * technical activity slide because the online count is aggregate data,
 * but it carries no landing attributes without consent.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Geoip;
use GreenPNG\Core\Gr_Ip_Quality;
use GreenPNG\Core\Gr_Request;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Privacy\Gr_Consent;
use GreenPNG\Storage\Gr_Session_Repository;
use GreenPNG\Storage\Gr_Touchpoint_Repository;

/**
 * Front-end attribution checkpoint (template_redirect@10): identity,
 * session, and touchpoint state for one request.
 */
final class Gr_Attribution_Listener {

    /**
     * Identity service (dual-track).
     *
     * @var Gr_Identity
     */
    private Gr_Identity $identity;

    /**
     * Sessions repository.
     *
     * @var Gr_Session_Repository
     */
    private Gr_Session_Repository $sessions;

    /**
     * Touchpoints repository.
     *
     * @var Gr_Touchpoint_Repository
     */
    private Gr_Touchpoint_Repository $touchpoints;

    /**
     * Settings service.
     *
     * @var Gr_Settings
     */
    private Gr_Settings $settings;

    /**
     * Wires the collaborators.
     *
     * @param Gr_Identity              $identity    Identity service.
     * @param Gr_Session_Repository    $sessions    Sessions repository.
     * @param Gr_Touchpoint_Repository $touchpoints Touchpoints repository.
     * @param Gr_Settings              $settings    Settings service.
     */
    public function __construct(
        Gr_Identity $identity,
        Gr_Session_Repository $sessions,
        Gr_Touchpoint_Repository $touchpoints,
        Gr_Settings $settings
    ) {
        $this->identity    = $identity;
        $this->sessions    = $sessions;
        $this->touchpoints = $touchpoints;
        $this->settings    = $settings;
    }

    /**
     * One front-end request: issue identity under consent, then record
     * the session activity slide (landing attributes only with consent)
     * and, for campaign entries, the touchpoint row. attribution_enabled
     * gates every marketing write; the technical session slide stays on
     * because the online count is aggregate, not attribution data.
     *
     * @return void
     */
    public function handle(): void {
        $enabled = 1 === (int) $this->settings->get( 'attribution_enabled' );
        $consent = $enabled && Gr_Consent::allows( 'marketing' );

        if ( $consent ) {
            // Idempotent: an existing verified cookie identity is kept,
            // so consent given mid-visit never resets attribution.
            $this->identity->issue();
        }

        $visitor = $this->identity->visitor_id();
        $session = $this->identity->session_id();

        $parsed = Gr_Attribution_Params::parse( $this->query_params() );
        $host   = $this->referrer_host();
        $parsed = Gr_Attribution_Params::apply_referrer( $parsed, $host );

        // The country code and the datacenter category are landing
        // attributes, so both ride the consent gate like every other
        // one (docs/07 §1); without consent the technical slide stays
        // attribute-free and the dashboard country chart keeps its
        // Unknown bucket. GeoIP is the purely local DB-IP lookup; the
        // hosting category is the in-memory packed-range search
        // (ADR-0011 D4) — zero SQL either way, and the write lands on
        // the same upsert the touch already runs.
        $landing = array();
        if ( $consent ) {
            $client_ip               = gr_get_client_ip();
            $landing['country_code'] = Gr_Geoip::country( $client_ip );
            $landing['ip_quality']   = Gr_Ip_Quality::category( $client_ip );
        }

        if ( $consent && Gr_Attribution_Params::is_campaign_entry( $parsed ) ) {
            $landing = array_merge(
                $landing,
                array(
                    'channel'       => $parsed['channel'],
                    'utm_source'    => $parsed['utm_source'],
                    'utm_medium'    => $parsed['utm_medium'],
                    'utm_campaign'  => $parsed['utm_campaign'],
                    'click_id'      => $parsed['click_id'],
                    'landing_path'  => $this->landing_path(),
                    'referrer_host' => $host,
                )
            );

            $this->touchpoints->record(
                $visitor,
                array_merge( $parsed, array( 'referrer_host' => $host ) ),
                $session,
                $this->landing_url()
            );
        }

        $this->sessions->touch( $visitor, $session, $landing );
    }

    /**
     * Sanitized query params: attribution reads a fixed vocabulary, so
     * anything non-scalar or unknown is dropped before parse().
     *
     * @return array<string, string>
     */
    private function query_params(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- attribution reads passive marketing params; no state change depends on them.
        $raw = $_GET;

        if ( ! is_array( $raw ) ) {
            return array();
        }

        $params = array();
        foreach ( $raw as $key => $value ) {
            if ( is_string( $key ) && is_scalar( $value ) ) {
                $params[ $key ] = sanitize_text_field( wp_unslash( $value ) );
            }
        }

        return $params;
    }

    /**
     * External referrer host, '' when the header is absent or relative.
     * A host equal to this site's own is not a referral.
     *
     * @return string
     */
    private function referrer_host(): string {
        $referrer = Gr_Request::referrer();
        if ( '' === $referrer ) {
            return '';
        }

        $host = (string) wp_parse_url( $referrer, PHP_URL_HOST );
        if ( '' === $host ) {
            return '';
        }

        $own = (string) wp_parse_url( home_url(), PHP_URL_HOST );

        return ( 0 === strcasecmp( $host, $own ) ) ? '' : strtolower( $host );
    }

    /**
     * Landing path for the session row, clamped to the column width.
     *
     * @return string
     */
    private function landing_path(): string {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return '';
        }

        $uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        $path = (string) wp_parse_url( $uri, PHP_URL_PATH );

        return substr( $path, 0, 191 );
    }

    /**
     * Full landing URL for the touchpoint row, clamped to the column
     * width.
     *
     * @return string
     */
    private function landing_url(): string {
        return substr( esc_url_raw( home_url( $this->landing_path() ) ), 0, 191 );
    }
}
