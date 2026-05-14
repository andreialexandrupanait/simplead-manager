--
-- PostgreSQL database dump
--

\restrict LakcBIjEOjpsFQ6JIhdL26LBO22IrLos756EWXPJQEd8Y50Lkz9ZidjXJq5HIqD

-- Dumped from database version 16.12
-- Dumped by pg_dump version 18.3

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--

-- *not* creating schema, since initdb creates it


--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA public IS '';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: activity_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.activity_logs (
    id bigint NOT NULL,
    site_id bigint,
    user_id bigint,
    type character varying(255) NOT NULL,
    severity character varying(255) DEFAULT 'info'::character varying NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    metadata jsonb,
    icon character varying(255),
    url character varying(255),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: activity_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.activity_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: activity_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.activity_logs_id_seq OWNED BY public.activity_logs.id;


--
-- Name: analytics_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.analytics_cache (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    date_range character varying(255) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    data jsonb NOT NULL,
    fetched_at timestamp(0) without time zone NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL
);


--
-- Name: analytics_cache_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.analytics_cache_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: analytics_cache_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.analytics_cache_id_seq OWNED BY public.analytics_cache.id;


--
-- Name: analytics_connections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.analytics_connections (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    google_connection_id bigint NOT NULL,
    property_id character varying(255) NOT NULL,
    property_name character varying(255),
    data_stream_id character varying(255),
    data_stream_url character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    last_sync_at timestamp(0) without time zone,
    last_error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    next_sync_at timestamp(0) without time zone,
    interval_minutes integer DEFAULT 1440 NOT NULL
);


--
-- Name: analytics_connections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.analytics_connections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: analytics_connections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.analytics_connections_id_seq OWNED BY public.analytics_connections.id;


--
-- Name: app_backup_configs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.app_backup_configs (
    id bigint NOT NULL,
    is_enabled boolean DEFAULT false NOT NULL,
    frequency character varying(255) DEFAULT 'daily'::character varying NOT NULL,
    "time" character varying(255) DEFAULT '02:00'::character varying NOT NULL,
    day_of_week smallint,
    day_of_month smallint,
    timezone character varying(255) DEFAULT 'Europe/Bucharest'::character varying NOT NULL,
    type character varying(255) DEFAULT 'full'::character varying NOT NULL,
    components jsonb,
    storage_destination_id bigint,
    retention_type character varying(255) DEFAULT 'count'::character varying NOT NULL,
    retention_value integer DEFAULT 7 NOT NULL,
    last_backup_at timestamp(0) without time zone,
    next_backup_at timestamp(0) without time zone,
    last_backup_status character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: app_backup_configs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.app_backup_configs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: app_backup_configs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.app_backup_configs_id_seq OWNED BY public.app_backup_configs.id;


--
-- Name: app_backups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.app_backups (
    id bigint NOT NULL,
    type character varying(255) NOT NULL,
    trigger character varying(255) DEFAULT 'manual'::character varying NOT NULL,
    components jsonb,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    progress smallint DEFAULT '0'::smallint NOT NULL,
    error_message text,
    log jsonb,
    storage_destination_id bigint,
    storage_path character varying(255),
    file_name character varying(255),
    file_size bigint,
    checksum character varying(255),
    component_sizes jsonb,
    app_version character varying(255),
    laravel_version character varying(255),
    php_version character varying(255),
    sites_count integer DEFAULT 0 NOT NULL,
    users_count integer DEFAULT 0 NOT NULL,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    duration_seconds integer,
    is_locked boolean DEFAULT false NOT NULL,
    lock_reason character varying(255),
    expires_at timestamp(0) without time zone,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: app_backups_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.app_backups_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: app_backups_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.app_backups_id_seq OWNED BY public.app_backups.id;


--
-- Name: app_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.app_settings (
    id bigint NOT NULL,
    "group" character varying(255) DEFAULT 'general'::character varying NOT NULL,
    key character varying(255) NOT NULL,
    value text,
    type character varying(255) DEFAULT 'string'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: app_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.app_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: app_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.app_settings_id_seq OWNED BY public.app_settings.id;


--
-- Name: backlink_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.backlink_snapshots (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    date date NOT NULL,
    total_backlinks integer DEFAULT 0 NOT NULL,
    referring_domains integer DEFAULT 0 NOT NULL,
    new_backlinks integer DEFAULT 0 NOT NULL,
    lost_backlinks integer DEFAULT 0 NOT NULL,
    dofollow_count integer DEFAULT 0 NOT NULL,
    nofollow_count integer DEFAULT 0 NOT NULL,
    anchor_text_distribution jsonb DEFAULT '[]'::jsonb NOT NULL,
    top_pages jsonb DEFAULT '[]'::jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: backlink_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.backlink_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: backlink_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.backlink_snapshots_id_seq OWNED BY public.backlink_snapshots.id;


--
-- Name: backlinks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.backlinks (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    source_url text NOT NULL,
    target_url text NOT NULL,
    source_domain character varying(255) NOT NULL,
    anchor_text text,
    is_nofollow boolean DEFAULT false NOT NULL,
    first_seen_at date NOT NULL,
    last_seen_at date NOT NULL,
    lost_at date,
    source_type character varying(30) DEFAULT 'gsc'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    spam_score smallint,
    link_type character varying(30),
    page_title text,
    context_text text,
    outbound_links_count integer,
    link_position character varying(30),
    anchor_type character varying(30),
    last_verified_at timestamp(0) without time zone,
    is_alive boolean DEFAULT true NOT NULL
);


--
-- Name: backlinks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.backlinks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: backlinks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.backlinks_id_seq OWNED BY public.backlinks.id;


--
-- Name: backup_configs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.backup_configs (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    is_enabled boolean DEFAULT false NOT NULL,
    frequency character varying(255) DEFAULT 'daily'::character varying NOT NULL,
    "time" character varying(255) DEFAULT '03:00'::character varying NOT NULL,
    day_of_week smallint,
    day_of_month smallint,
    timezone character varying(255) DEFAULT 'UTC'::character varying NOT NULL,
    type character varying(255) DEFAULT 'full'::character varying NOT NULL,
    exclude_paths json,
    exclude_tables json,
    storage_destination_id bigint,
    retention_type character varying(255) DEFAULT 'count'::character varying NOT NULL,
    retention_value integer DEFAULT 10 NOT NULL,
    backup_before_updates boolean DEFAULT false NOT NULL,
    last_backup_at timestamp(0) without time zone,
    next_backup_at timestamp(0) without time zone,
    last_backup_status character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    incremental_frequency character varying(255),
    full_backup_day_of_week smallint,
    last_full_backup_at timestamp(0) without time zone,
    secondary_storage_destination_id bigint,
    use_streaming boolean DEFAULT false NOT NULL
);


--
-- Name: backup_configs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.backup_configs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: backup_configs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.backup_configs_id_seq OWNED BY public.backup_configs.id;


--
-- Name: backups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.backups (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    storage_destination_id bigint,
    type character varying(255) NOT NULL,
    trigger character varying(255) DEFAULT 'manual'::character varying NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    error_message text,
    file_path character varying(255),
    file_name character varying(255),
    file_size bigint,
    checksum character varying(255),
    includes_files boolean DEFAULT false NOT NULL,
    includes_database boolean DEFAULT false NOT NULL,
    wp_version character varying(255),
    php_version character varying(255),
    plugins_count integer,
    themes_count integer,
    db_size_mb numeric(10,2),
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    duration_seconds integer,
    is_locked boolean DEFAULT false NOT NULL,
    lock_reason character varying(255),
    expires_at timestamp(0) without time zone,
    last_restored_at timestamp(0) without time zone,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    stage character varying(255),
    progress_percent smallint DEFAULT '0'::smallint NOT NULL,
    progress_message character varying(255),
    restore_status character varying(255),
    restore_stage character varying(255),
    restore_progress_percent smallint DEFAULT '0'::smallint NOT NULL,
    restore_progress_message character varying(255),
    restore_error_message text,
    preparation_method character varying(20),
    parent_backup_id bigint,
    manifest_path character varying(255),
    files_changed_count integer,
    files_deleted_count integer,
    files_total_count integer,
    auto_retry_count smallint DEFAULT 0 NOT NULL,
    verified_at timestamp without time zone,
    verification_status character varying(20) DEFAULT 'never_tested'::character varying NOT NULL,
    verification_message text,
    replicas jsonb DEFAULT '[]'::jsonb NOT NULL,
    format character varying(20) DEFAULT 'v2-zip'::character varying NOT NULL,
    CONSTRAINT backups_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'failed'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: backups_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.backups_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: backups_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.backups_id_seq OWNED BY public.backups.id;


--
-- Name: client_costs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.client_costs (
    id bigint NOT NULL,
    client_id bigint NOT NULL,
    site_id bigint,
    type character varying(255) NOT NULL,
    description character varying(255) NOT NULL,
    amount numeric(10,2) NOT NULL,
    currency character varying(3) DEFAULT 'EUR'::character varying,
    is_recurring boolean DEFAULT false,
    recurring_interval character varying(255),
    starts_at date,
    ends_at date,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: client_costs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.client_costs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: client_costs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.client_costs_id_seq OWNED BY public.client_costs.id;


--
-- Name: client_revenues; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.client_revenues (
    id bigint NOT NULL,
    client_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    description character varying(255) NOT NULL,
    amount numeric(10,2) NOT NULL,
    currency character varying(3) DEFAULT 'EUR'::character varying,
    is_recurring boolean DEFAULT false,
    recurring_interval character varying(255),
    starts_at date,
    ends_at date,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: client_revenues_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.client_revenues_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: client_revenues_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.client_revenues_id_seq OWNED BY public.client_revenues.id;


--
-- Name: client_user; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.client_user (
    id bigint NOT NULL,
    client_id bigint NOT NULL,
    user_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: client_user_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.client_user_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: client_user_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.client_user_id_seq OWNED BY public.client_user.id;


--
-- Name: clients; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.clients (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255),
    phone character varying(255),
    company character varying(255),
    logo character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    website character varying(255),
    address character varying(255),
    city character varying(255),
    country character varying(255),
    vat_number character varying(255),
    registration_number character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    deleted_at timestamp(0) without time zone,
    portal_token character varying(64),
    portal_enabled boolean DEFAULT false NOT NULL,
    county character varying(100),
    postal_code character varying(20),
    vat_payer boolean DEFAULT false NOT NULL,
    company_status character varying(255)
);


--
-- Name: clients_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.clients_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: clients_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.clients_id_seq OWNED BY public.clients.id;


--
-- Name: cloudflare_cache_purges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cloudflare_cache_purges (
    id bigint NOT NULL,
    site_cloudflare_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    targets jsonb,
    purged_by bigint,
    purged_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: cloudflare_cache_purges_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.cloudflare_cache_purges_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cloudflare_cache_purges_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.cloudflare_cache_purges_id_seq OWNED BY public.cloudflare_cache_purges.id;


--
-- Name: cloudflare_connections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cloudflare_connections (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    api_token text NOT NULL,
    account_id character varying(255),
    account_email character varying(255),
    is_valid boolean DEFAULT false NOT NULL,
    last_validated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: cloudflare_connections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.cloudflare_connections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cloudflare_connections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.cloudflare_connections_id_seq OWNED BY public.cloudflare_connections.id;


--
-- Name: competitor_keyword_positions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.competitor_keyword_positions (
    id bigint NOT NULL,
    competitor_site_id bigint NOT NULL,
    keyword character varying(255) NOT NULL,
    "position" double precision,
    url text,
    date date NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: competitor_keyword_positions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.competitor_keyword_positions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: competitor_keyword_positions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.competitor_keyword_positions_id_seq OWNED BY public.competitor_keyword_positions.id;


--
-- Name: competitor_sites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.competitor_sites (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    competitor_url character varying(255) NOT NULL,
    competitor_name character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: competitor_sites_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.competitor_sites_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: competitor_sites_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.competitor_sites_id_seq OWNED BY public.competitor_sites.id;


--
-- Name: core_file_checks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_file_checks (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    wp_version character varying(255),
    total_files integer DEFAULT 0 NOT NULL,
    modified_count integer DEFAULT 0 NOT NULL,
    missing_count integer DEFAULT 0 NOT NULL,
    unknown_count integer DEFAULT 0 NOT NULL,
    modified_files jsonb,
    missing_files jsonb,
    unknown_files jsonb,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    error_message text,
    checked_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: core_file_checks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.core_file_checks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: core_file_checks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.core_file_checks_id_seq OWNED BY public.core_file_checks.id;


--
-- Name: crawled_pages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.crawled_pages (
    id bigint NOT NULL,
    site_crawl_id bigint NOT NULL,
    url character varying(2048) NOT NULL,
    status_code smallint,
    content_type character varying(255),
    response_time_ms integer,
    content_length integer,
    depth integer DEFAULT 0 NOT NULL,
    title text,
    title_length smallint DEFAULT 0 NOT NULL,
    meta_description text,
    meta_desc_length smallint DEFAULT 0 NOT NULL,
    canonical_url character varying(2048),
    canonical_self_ref boolean DEFAULT false NOT NULL,
    meta_robots text,
    x_robots_tag text,
    h1_tags jsonb,
    h1_count smallint DEFAULT 0 NOT NULL,
    h2_count smallint DEFAULT 0 NOT NULL,
    h3_count smallint DEFAULT 0 NOT NULL,
    word_count integer DEFAULT 0 NOT NULL,
    readability_score double precision,
    internal_links_count integer DEFAULT 0 NOT NULL,
    external_links_count integer DEFAULT 0 NOT NULL,
    internal_links jsonb,
    external_links jsonb,
    images_count integer DEFAULT 0 NOT NULL,
    images_without_alt integer DEFAULT 0 NOT NULL,
    structured_data_types jsonb,
    hreflang jsonb,
    og_title text,
    og_description text,
    og_image character varying(2048),
    redirect_url character varying(2048),
    redirect_status_code smallint,
    issues jsonb,
    crawled_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    images jsonb,
    scripts jsonb,
    stylesheets jsonb,
    is_https boolean DEFAULT false,
    has_mixed_content boolean DEFAULT false,
    h4_count smallint DEFAULT '0'::smallint NOT NULL,
    h5_count smallint DEFAULT '0'::smallint NOT NULL,
    h6_count smallint DEFAULT '0'::smallint NOT NULL
);


--
-- Name: crawled_pages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.crawled_pages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: crawled_pages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.crawled_pages_id_seq OWNED BY public.crawled_pages.id;


--
-- Name: dashboard_widgets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dashboard_widgets (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    widget_type character varying(50) NOT NULL,
    config jsonb,
    grid_x smallint DEFAULT '0'::smallint NOT NULL,
    grid_y smallint DEFAULT '0'::smallint NOT NULL,
    grid_w smallint DEFAULT '4'::smallint NOT NULL,
    grid_h smallint DEFAULT '2'::smallint NOT NULL,
    is_visible boolean DEFAULT true NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dashboard_widgets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dashboard_widgets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dashboard_widgets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dashboard_widgets_id_seq OWNED BY public.dashboard_widgets.id;


--
-- Name: database_cleanup_configs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.database_cleanup_configs (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    is_enabled boolean DEFAULT false NOT NULL,
    frequency character varying(255) DEFAULT 'monthly'::character varying NOT NULL,
    auto_clean_types jsonb,
    next_cleanup_at timestamp(0) without time zone,
    last_cleanup_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: database_cleanup_configs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.database_cleanup_configs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: database_cleanup_configs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.database_cleanup_configs_id_seq OWNED BY public.database_cleanup_configs.id;


--
-- Name: database_cleanups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.database_cleanups (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    revisions_deleted integer DEFAULT 0 NOT NULL,
    auto_drafts_deleted integer DEFAULT 0 NOT NULL,
    trash_posts_deleted integer DEFAULT 0 NOT NULL,
    spam_comments_deleted integer DEFAULT 0 NOT NULL,
    trash_comments_deleted integer DEFAULT 0 NOT NULL,
    transients_deleted integer DEFAULT 0 NOT NULL,
    orphaned_meta_deleted integer DEFAULT 0 NOT NULL,
    space_saved bigint DEFAULT '0'::bigint NOT NULL,
    status character varying(255) DEFAULT 'completed'::character varying NOT NULL,
    error_message text,
    cleaned_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    revisions_saved bigint DEFAULT '0'::bigint NOT NULL,
    auto_drafts_saved bigint DEFAULT '0'::bigint NOT NULL,
    trash_posts_saved bigint DEFAULT '0'::bigint NOT NULL,
    spam_comments_saved bigint DEFAULT '0'::bigint NOT NULL,
    trash_comments_saved bigint DEFAULT '0'::bigint NOT NULL,
    transients_saved bigint DEFAULT '0'::bigint NOT NULL,
    orphaned_saved bigint DEFAULT '0'::bigint NOT NULL
);


--
-- Name: database_cleanups_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.database_cleanups_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: database_cleanups_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.database_cleanups_id_seq OWNED BY public.database_cleanups.id;


--
-- Name: database_health_checks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.database_health_checks (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    total_size bigint DEFAULT '0'::bigint NOT NULL,
    total_tables integer DEFAULT 0 NOT NULL,
    tables_data jsonb,
    largest_tables jsonb,
    tables_with_overhead jsonb,
    myisam_count integer DEFAULT 0 NOT NULL,
    autoload_size bigint DEFAULT '0'::bigint NOT NULL,
    status character varying(255) DEFAULT 'healthy'::character varying NOT NULL,
    checked_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: database_health_checks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.database_health_checks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: database_health_checks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.database_health_checks_id_seq OWNED BY public.database_health_checks.id;


--
-- Name: dns_changes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dns_changes (
    id bigint NOT NULL,
    dns_monitor_id bigint NOT NULL,
    record_type character varying(255) NOT NULL,
    old_value jsonb,
    new_value jsonb,
    detected_at timestamp without time zone NOT NULL,
    acknowledged_at timestamp without time zone,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: dns_changes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dns_changes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dns_changes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dns_changes_id_seq OWNED BY public.dns_changes.id;


--
-- Name: dns_monitors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dns_monitors (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    domain character varying(255) NOT NULL,
    is_active boolean DEFAULT true,
    interval_minutes smallint DEFAULT 360,
    last_checked_at timestamp without time zone,
    next_check_at timestamp without time zone,
    current_records jsonb,
    previous_records jsonb,
    has_changes boolean DEFAULT false,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: dns_monitors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dns_monitors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dns_monitors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dns_monitors_id_seq OWNED BY public.dns_monitors.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: google_connections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.google_connections (
    id bigint NOT NULL,
    google_id character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    name character varying(255),
    avatar_url character varying(255),
    access_token text NOT NULL,
    refresh_token text NOT NULL,
    token_expires_at timestamp(0) without time zone NOT NULL,
    scopes jsonb,
    is_active boolean DEFAULT true NOT NULL,
    last_used_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: google_connections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.google_connections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: google_connections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.google_connections_id_seq OWNED BY public.google_connections.id;


--
-- Name: health_score_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.health_score_history (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    score smallint NOT NULL,
    uptime_score smallint DEFAULT 0 NOT NULL,
    security_score smallint DEFAULT 0 NOT NULL,
    updates_score smallint DEFAULT 0 NOT NULL,
    performance_score smallint DEFAULT 0 NOT NULL,
    recorded_at date NOT NULL,
    created_at timestamp without time zone
);


--
-- Name: health_score_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.health_score_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: health_score_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.health_score_history_id_seq OWNED BY public.health_score_history.id;


--
-- Name: in_app_notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.in_app_notifications (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    type character varying(255) DEFAULT 'info'::character varying NOT NULL,
    title character varying(255) NOT NULL,
    message text,
    data jsonb,
    read_at timestamp without time zone,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: in_app_notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.in_app_notifications_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: in_app_notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.in_app_notifications_id_seq OWNED BY public.in_app_notifications.id;


--
-- Name: invitations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invitations (
    id bigint NOT NULL,
    email character varying(255) NOT NULL,
    role character varying(255) DEFAULT 'manager'::character varying NOT NULL,
    token character varying(64) NOT NULL,
    invited_by bigint NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    accepted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: invitations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.invitations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: invitations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.invitations_id_seq OWNED BY public.invitations.id;


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: keyword_page_mappings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.keyword_page_mappings (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    tracked_keyword_id bigint NOT NULL,
    url text NOT NULL,
    source character varying(30) DEFAULT 'gsc_auto'::character varying NOT NULL,
    clicks integer DEFAULT 0 NOT NULL,
    impressions integer DEFAULT 0 NOT NULL,
    avg_position double precision,
    last_seen_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: keyword_page_mappings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.keyword_page_mappings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: keyword_page_mappings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.keyword_page_mappings_id_seq OWNED BY public.keyword_page_mappings.id;


--
-- Name: keyword_positions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.keyword_positions (
    id bigint NOT NULL,
    tracked_keyword_id bigint NOT NULL,
    date date NOT NULL,
    "position" double precision,
    clicks integer DEFAULT 0 NOT NULL,
    impressions integer DEFAULT 0 NOT NULL,
    ctr double precision DEFAULT '0'::double precision NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: keyword_positions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.keyword_positions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: keyword_positions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.keyword_positions_id_seq OWNED BY public.keyword_positions.id;


--
-- Name: keyword_research_results; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.keyword_research_results (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    site_id bigint,
    seed_keyword character varying(255) NOT NULL,
    language character varying(10) DEFAULT 'ro'::character varying NOT NULL,
    country character varying(10) DEFAULT 'ro'::character varying NOT NULL,
    suggestions jsonb,
    gsc_data jsonb,
    clusters jsonb,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: keyword_research_results_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.keyword_research_results_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: keyword_research_results_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.keyword_research_results_id_seq OWNED BY public.keyword_research_results.id;


--
-- Name: maintenance_plan_modules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.maintenance_plan_modules (
    id bigint NOT NULL,
    maintenance_plan_id bigint NOT NULL,
    module_key character varying(255) NOT NULL,
    is_enabled boolean DEFAULT false NOT NULL,
    interval_minutes integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    config jsonb
);


--
-- Name: maintenance_plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.maintenance_plans (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255),
    modules json,
    is_default boolean DEFAULT false NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    security_settings jsonb,
    tweak_settings jsonb,
    include_modules boolean DEFAULT true NOT NULL,
    include_security boolean DEFAULT false NOT NULL,
    include_tweaks boolean DEFAULT false NOT NULL,
    source_site_id bigint,
    created_by bigint
);


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: notification_channels; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_channels (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    type character varying(255) NOT NULL,
    config text NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    last_used_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    event_subscriptions jsonb,
    last_error character varying(255),
    last_error_at timestamp(0) without time zone
);


--
-- Name: notification_channels_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notification_channels_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_channels_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notification_channels_id_seq OWNED BY public.notification_channels.id;


--
-- Name: notification_escalation_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_escalation_rules (
    id bigint NOT NULL,
    source_channel_id bigint NOT NULL,
    escalation_channel_id bigint NOT NULL,
    delay_minutes integer DEFAULT 15 NOT NULL,
    severity character varying(255) DEFAULT 'critical'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: notification_escalation_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notification_escalation_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_escalation_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notification_escalation_rules_id_seq OWNED BY public.notification_escalation_rules.id;


--
-- Name: notification_event_preferences; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_event_preferences (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    notification_channel_id bigint NOT NULL,
    event character varying(255) NOT NULL,
    enabled boolean DEFAULT true,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: notification_event_preferences_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notification_event_preferences_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_event_preferences_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notification_event_preferences_id_seq OWNED BY public.notification_event_preferences.id;


--
-- Name: notification_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_logs (
    id bigint NOT NULL,
    notification_channel_id bigint NOT NULL,
    site_id bigint,
    event character varying(255) NOT NULL,
    channel_type character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    message text,
    error_message text,
    metadata jsonb,
    response_code integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    severity character varying(255),
    ack_token character varying(64),
    acknowledged_at timestamp(0) without time zone,
    escalated boolean DEFAULT false NOT NULL
);


--
-- Name: notification_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notification_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notification_logs_id_seq OWNED BY public.notification_logs.id;


--
-- Name: notification_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_templates (
    id bigint NOT NULL,
    event character varying(255) NOT NULL,
    title_template character varying(255) NOT NULL,
    message_template text NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: notification_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notification_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notification_templates_id_seq OWNED BY public.notification_templates.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: performance_monitors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.performance_monitors (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    frequency character varying(255) DEFAULT 'daily'::character varying NOT NULL,
    test_time character varying(255) DEFAULT '04:00'::character varying NOT NULL,
    day_of_week smallint,
    alert_on_score_drop boolean DEFAULT true NOT NULL,
    score_drop_threshold smallint DEFAULT '10'::smallint NOT NULL,
    alert_on_poor_vitals boolean DEFAULT false NOT NULL,
    latest_mobile_score smallint,
    latest_desktop_score smallint,
    previous_mobile_score smallint,
    previous_desktop_score smallint,
    last_tested_at timestamp(0) without time zone,
    next_test_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    budgets jsonb,
    interval_minutes integer DEFAULT 10080 NOT NULL,
    competitor_urls jsonb
);


--
-- Name: performance_monitors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.performance_monitors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: performance_monitors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.performance_monitors_id_seq OWNED BY public.performance_monitors.id;


--
-- Name: performance_pages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.performance_pages (
    id bigint NOT NULL,
    performance_monitor_id bigint NOT NULL,
    label character varying(255) NOT NULL,
    url character varying(255) NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: performance_pages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.performance_pages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: performance_pages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.performance_pages_id_seq OWNED BY public.performance_pages.id;


--
-- Name: performance_tests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.performance_tests (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    performance_monitor_id bigint NOT NULL,
    device character varying(255) NOT NULL,
    url character varying(255) NOT NULL,
    performance_score smallint,
    accessibility_score smallint,
    best_practices_score smallint,
    fcp double precision,
    lcp double precision,
    cls double precision,
    tbt double precision,
    si double precision,
    tti double precision,
    field_fcp double precision,
    field_lcp double precision,
    field_cls double precision,
    field_inp double precision,
    field_ttfb double precision,
    total_requests integer,
    total_size_bytes bigint,
    html_size bigint,
    css_size bigint,
    js_size bigint,
    image_size bigint,
    font_size bigint,
    opportunities jsonb,
    diagnostics jsonb,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    error_message text,
    lighthouse_version character varying(255),
    tested_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    performance_page_id bigint,
    third_party_scripts jsonb,
    dom_elements integer,
    dom_max_depth integer,
    dom_max_children integer,
    unused_js_bytes integer,
    unused_css_bytes integer,
    unused_js_details jsonb,
    unused_css_details jsonb,
    image_audit jsonb,
    wp_health_checks jsonb,
    screenshot_final text,
    filmstrip jsonb,
    is_competitor boolean DEFAULT false NOT NULL,
    competitor_url character varying(255)
);


--
-- Name: performance_tests_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.performance_tests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: performance_tests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.performance_tests_id_seq OWNED BY public.performance_tests.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    token character varying(64) NOT NULL,
    abilities jsonb,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: php_error_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.php_error_logs (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    level character varying(255) NOT NULL,
    message text NOT NULL,
    file character varying(255),
    line integer,
    message_hash character varying(32) NOT NULL,
    count integer DEFAULT 1,
    first_seen_at timestamp without time zone NOT NULL,
    last_seen_at timestamp without time zone NOT NULL,
    is_resolved boolean DEFAULT false,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: php_error_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.php_error_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: php_error_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.php_error_logs_id_seq OWNED BY public.php_error_logs.id;


--
-- Name: plugin_conflicts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.plugin_conflicts (
    id bigint NOT NULL,
    plugin_a_slug character varying(255) NOT NULL,
    plugin_b_slug character varying(255) NOT NULL,
    conflict_type character varying(255) NOT NULL,
    description text NOT NULL,
    severity character varying(255) NOT NULL,
    source_url character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: plugin_conflicts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.plugin_conflicts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plugin_conflicts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.plugin_conflicts_id_seq OWNED BY public.plugin_conflicts.id;


--
-- Name: recommendation_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recommendation_templates (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    recommendations jsonb DEFAULT '[]'::jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: recommendation_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.recommendation_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: recommendation_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.recommendation_templates_id_seq OWNED BY public.recommendation_templates.id;


--
-- Name: report_recommendations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.report_recommendations (
    id bigint NOT NULL,
    report_id bigint,
    site_id bigint NOT NULL,
    priority character varying(10) DEFAULT 'medium'::character varying NOT NULL,
    category character varying(20) DEFAULT 'technical'::character varying NOT NULL,
    title character varying(255) NOT NULL,
    description text NOT NULL,
    is_auto_generated boolean DEFAULT false NOT NULL,
    is_included boolean DEFAULT true NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: report_recommendations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.report_recommendations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: report_recommendations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.report_recommendations_id_seq OWNED BY public.report_recommendations.id;


--
-- Name: report_schedules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.report_schedules (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    report_template_id bigint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    frequency character varying(255) NOT NULL,
    day_of_week smallint,
    day_of_month smallint,
    "time" character varying(5) DEFAULT '08:00'::character varying NOT NULL,
    timezone character varying(255) DEFAULT 'Europe/Bucharest'::character varying NOT NULL,
    period character varying(255) NOT NULL,
    recipient_emails jsonb,
    send_copy_to_admin boolean DEFAULT true NOT NULL,
    email_subject character varying(255),
    email_body text,
    last_generated_at timestamp(0) without time zone,
    last_sent_at timestamp(0) without time zone,
    next_run_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    reminder_sent_at timestamp(0) without time zone,
    consecutive_failures smallint DEFAULT 0 NOT NULL,
    last_failure_reason text
);


--
-- Name: report_schedules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.report_schedules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: report_schedules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.report_schedules_id_seq OWNED BY public.report_schedules.id;


--
-- Name: report_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.report_templates (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    sections jsonb NOT NULL,
    company_name character varying(255),
    company_logo_path character varying(255),
    company_website character varying(255),
    primary_color character varying(7) DEFAULT '#7C3AED'::character varying NOT NULL,
    intro_text text,
    closing_text text,
    is_default boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    section_overrides jsonb,
    section_options jsonb,
    language character varying(5) DEFAULT 'ro'::character varying NOT NULL
);


--
-- Name: report_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.report_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: report_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.report_templates_id_seq OWNED BY public.report_templates.id;


--
-- Name: reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reports (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    report_template_id bigint,
    report_schedule_id bigint,
    title character varying(255) NOT NULL,
    period_start date NOT NULL,
    period_end date NOT NULL,
    file_path character varying(255),
    file_name character varying(255),
    file_size bigint,
    page_count integer,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    error_message text,
    trigger character varying(255) DEFAULT 'manual'::character varying NOT NULL,
    was_sent boolean DEFAULT false NOT NULL,
    sent_at timestamp(0) without time zone,
    sent_to jsonb,
    data_snapshot jsonb,
    generated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    view_token character varying(32)
);


--
-- Name: reports_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.reports_id_seq OWNED BY public.reports.id;


--
-- Name: rollback_points; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rollback_points (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    from_version character varying(255) NOT NULL,
    to_version character varying(255) NOT NULL,
    backup_reference character varying(255),
    status character varying(255) DEFAULT 'available'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    CONSTRAINT rollback_points_status_check CHECK (((status)::text = ANY (ARRAY[('available'::character varying)::text, ('used'::character varying)::text, ('expired'::character varying)::text]))),
    CONSTRAINT rollback_points_type_check CHECK (((type)::text = ANY (ARRAY[('plugin'::character varying)::text, ('theme'::character varying)::text, ('core'::character varying)::text])))
);


--
-- Name: rollback_points_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rollback_points_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rollback_points_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rollback_points_id_seq OWNED BY public.rollback_points.id;


--
-- Name: safe_updates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.safe_updates (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    from_version character varying(255) NOT NULL,
    to_version character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    health_check_results jsonb,
    error_message text,
    auto_rollback boolean DEFAULT true NOT NULL,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    screenshot_before_path character varying(255),
    screenshot_after_path character varying(255),
    visual_regression_results jsonb,
    ai_risk_score smallint,
    ai_risk_assessment jsonb,
    CONSTRAINT safe_updates_status_check CHECK (((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('backing_up'::character varying)::text, ('updating'::character varying)::text, ('health_checking'::character varying)::text, ('rolling_back'::character varying)::text, ('completed'::character varying)::text, ('failed'::character varying)::text]))),
    CONSTRAINT safe_updates_type_check CHECK (((type)::text = ANY (ARRAY[('plugin'::character varying)::text, ('theme'::character varying)::text, ('core'::character varying)::text])))
);


--
-- Name: safe_updates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.safe_updates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: safe_updates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.safe_updates_id_seq OWNED BY public.safe_updates.id;


--
-- Name: search_console_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.search_console_cache (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    date_range character varying(255) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    data_type character varying(255) NOT NULL,
    data jsonb NOT NULL,
    fetched_at timestamp(0) without time zone NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL
);


--
-- Name: search_console_cache_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.search_console_cache_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: search_console_cache_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.search_console_cache_id_seq OWNED BY public.search_console_cache.id;


--
-- Name: search_console_connections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.search_console_connections (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    google_connection_id bigint NOT NULL,
    property_url character varying(255) NOT NULL,
    property_type character varying(255) DEFAULT 'url'::character varying NOT NULL,
    permission_level character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    last_sync_at timestamp(0) without time zone,
    last_error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    next_sync_at timestamp(0) without time zone,
    interval_minutes integer DEFAULT 1440 NOT NULL
);


--
-- Name: search_console_connections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.search_console_connections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: search_console_connections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.search_console_connections_id_seq OWNED BY public.search_console_connections.id;


--
-- Name: security_activity_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_activity_logs (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    event_type character varying(50) NOT NULL,
    username character varying(255),
    object_type character varying(50),
    object_name character varying(255),
    action character varying(100),
    ip_address inet,
    user_agent text,
    details jsonb,
    occurred_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    event_category character varying(20) DEFAULT 'security'::character varying
);


--
-- Name: security_activity_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_activity_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_activity_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_activity_logs_id_seq OWNED BY public.security_activity_logs.id;


--
-- Name: security_banned_ips; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_banned_ips (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    ip_address inet NOT NULL,
    reason text,
    blocked_attempts integer DEFAULT 0 NOT NULL,
    banned_at timestamp(0) without time zone NOT NULL,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: security_banned_ips_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_banned_ips_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_banned_ips_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_banned_ips_id_seq OWNED BY public.security_banned_ips.id;


--
-- Name: security_commands; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_commands (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    category character varying(50) NOT NULL,
    action character varying(100) NOT NULL,
    payload jsonb,
    priority character varying(20) DEFAULT 'normal'::character varying NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    picked_up_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    result jsonb,
    attempts smallint DEFAULT '0'::smallint NOT NULL,
    max_attempts smallint DEFAULT '3'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: security_commands_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_commands_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_commands_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_commands_id_seq OWNED BY public.security_commands.id;


--
-- Name: security_ip_lists; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_ip_lists (
    id bigint NOT NULL,
    site_id bigint,
    ip_address inet NOT NULL,
    list_type character varying(20) NOT NULL,
    reason text,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: security_ip_lists_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_ip_lists_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_ip_lists_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_ip_lists_id_seq OWNED BY public.security_ip_lists.id;


--
-- Name: security_issues; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_issues (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    security_scan_id bigint,
    category character varying(255) NOT NULL,
    type character varying(255) NOT NULL,
    severity character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    recommendation text,
    is_fixed boolean DEFAULT false NOT NULL,
    is_ignored boolean DEFAULT false NOT NULL,
    first_detected_at timestamp(0) without time zone,
    fixed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: security_issues_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_issues_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_issues_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_issues_id_seq OWNED BY public.security_issues.id;


--
-- Name: security_monitors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_monitors (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    interval_minutes integer DEFAULT 10080 NOT NULL,
    next_scan_at timestamp(0) without time zone,
    last_scan_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: security_monitors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_monitors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_monitors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_monitors_id_seq OWNED BY public.security_monitors.id;


--
-- Name: security_preset_site; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_preset_site (
    id bigint NOT NULL,
    security_preset_id bigint NOT NULL,
    site_id bigint NOT NULL,
    applied_at timestamp(0) without time zone,
    applied_version integer DEFAULT 1 NOT NULL
);


--
-- Name: security_preset_site_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_preset_site_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_preset_site_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_preset_site_id_seq OWNED BY public.security_preset_site.id;


--
-- Name: security_presets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_presets (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    settings jsonb NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: security_presets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_presets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_presets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_presets_id_seq OWNED BY public.security_presets.id;


--
-- Name: security_recommendations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_recommendations (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    key character varying(255) NOT NULL,
    category character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'unchecked'::character varying NOT NULL,
    can_auto_fix boolean DEFAULT false NOT NULL,
    last_checked_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: security_recommendations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_recommendations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_recommendations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_recommendations_id_seq OWNED BY public.security_recommendations.id;


--
-- Name: security_scans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_scans (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    score integer DEFAULT 0 NOT NULL,
    scores_breakdown jsonb,
    critical_count integer DEFAULT 0 NOT NULL,
    high_count integer DEFAULT 0 NOT NULL,
    medium_count integer DEFAULT 0 NOT NULL,
    low_count integer DEFAULT 0 NOT NULL,
    scan_duration integer,
    scanned_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: security_scans_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_scans_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_scans_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_scans_id_seq OWNED BY public.security_scans.id;


--
-- Name: security_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_settings (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    category character varying(50) NOT NULL,
    setting_key character varying(100) NOT NULL,
    setting_value jsonb,
    is_enabled boolean DEFAULT false NOT NULL,
    applied_at timestamp(0) without time zone,
    failed_at timestamp(0) without time zone,
    failure_reason text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: security_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_settings_id_seq OWNED BY public.security_settings.id;


--
-- Name: seo_alert_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_alert_rules (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    rule_type character varying(50) NOT NULL,
    threshold jsonb DEFAULT '{}'::jsonb NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    last_triggered_at timestamp(0) without time zone,
    cooldown_minutes integer DEFAULT 1440 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: seo_alert_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_alert_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_alert_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_alert_rules_id_seq OWNED BY public.seo_alert_rules.id;


--
-- Name: seo_audits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_audits (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    score integer DEFAULT 0 NOT NULL,
    critical_count integer DEFAULT 0 NOT NULL,
    high_count integer DEFAULT 0 NOT NULL,
    medium_count integer DEFAULT 0 NOT NULL,
    low_count integer DEFAULT 0 NOT NULL,
    info_count integer DEFAULT 0 NOT NULL,
    scan_duration integer,
    pages_crawled integer DEFAULT 0 NOT NULL,
    seo_plugin character varying(255),
    seo_plugin_version character varying(255),
    data jsonb,
    scanned_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    status character varying(30) DEFAULT 'pending'::character varying,
    error_message text,
    category_scores jsonb,
    sitemap_urls_count integer,
    security_headers jsonb,
    ssl_info jsonb,
    redirect_info jsonb,
    robots_txt_data jsonb,
    broken_links_count integer DEFAULT 0 NOT NULL,
    broken_images_count integer DEFAULT 0 NOT NULL,
    total_images_count integer DEFAULT 0 NOT NULL,
    redirect_pages_count integer DEFAULT 0 NOT NULL
);


--
-- Name: seo_audits_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_audits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_audits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_audits_id_seq OWNED BY public.seo_audits.id;


--
-- Name: seo_content_revisions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_content_revisions (
    id bigint NOT NULL,
    seo_content_id bigint NOT NULL,
    content text NOT NULL,
    meta_description text,
    source character varying(255) DEFAULT 'ai'::character varying NOT NULL,
    generation_params jsonb,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: seo_content_revisions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_content_revisions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_content_revisions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_content_revisions_id_seq OWNED BY public.seo_content_revisions.id;


--
-- Name: seo_contents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_contents (
    id bigint NOT NULL,
    site_id bigint,
    user_id bigint NOT NULL,
    title character varying(255) NOT NULL,
    slug character varying(255),
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    target_keyword character varying(255),
    secondary_keywords jsonb,
    brief text,
    content text,
    meta_description text,
    tone character varying(255),
    persona character varying(255),
    target_audience character varying(255),
    target_word_count integer,
    sections jsonb,
    seo_score_data jsonb,
    seo_score smallint,
    word_count integer,
    keyword_density double precision,
    wp_post_id integer,
    published_at timestamp without time zone,
    scheduled_at timestamp without time zone,
    created_at timestamp without time zone,
    updated_at timestamp without time zone,
    ai_provider character varying(255),
    ai_model character varying(255),
    ranking_position double precision,
    ranking_date date
);


--
-- Name: seo_contents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_contents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_contents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_contents_id_seq OWNED BY public.seo_contents.id;


--
-- Name: seo_images; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_images (
    id bigint NOT NULL,
    seo_audit_id bigint NOT NULL,
    seo_page_id bigint NOT NULL,
    image_url character varying(2048) NOT NULL,
    image_url_hash character varying(64) NOT NULL,
    alt_text character varying(1000),
    status_code smallint,
    is_broken boolean DEFAULT false NOT NULL,
    has_alt boolean DEFAULT true NOT NULL,
    has_lazy_loading boolean DEFAULT false NOT NULL,
    file_size_bytes integer,
    content_type character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: seo_images_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_images_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_images_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_images_id_seq OWNED BY public.seo_images.id;


--
-- Name: seo_issues; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_issues (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    seo_audit_id bigint NOT NULL,
    category character varying(255) NOT NULL,
    severity character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    url character varying(255),
    recommendation text,
    meta jsonb,
    resolved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: seo_issues_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_issues_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_issues_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_issues_id_seq OWNED BY public.seo_issues.id;


--
-- Name: seo_keyword_rankings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_keyword_rankings (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    keyword character varying(500) NOT NULL,
    keyword_hash character varying(64) NOT NULL,
    url character varying(2048),
    "position" numeric(6,2),
    clicks integer DEFAULT 0 NOT NULL,
    impressions integer DEFAULT 0 NOT NULL,
    ctr numeric(6,4) DEFAULT '0'::numeric NOT NULL,
    recorded_date date NOT NULL,
    is_tracked boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: seo_keyword_rankings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_keyword_rankings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_keyword_rankings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_keyword_rankings_id_seq OWNED BY public.seo_keyword_rankings.id;


--
-- Name: seo_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_links (
    id bigint NOT NULL,
    seo_audit_id bigint NOT NULL,
    seo_page_id bigint NOT NULL,
    target_url character varying(2048) NOT NULL,
    target_url_hash character varying(64) NOT NULL,
    type character varying(20) NOT NULL,
    rel character varying(50),
    anchor_text character varying(500),
    status_code smallint,
    is_broken boolean DEFAULT false,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: seo_links_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_links_id_seq OWNED BY public.seo_links.id;


--
-- Name: seo_monitors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_monitors (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    interval_minutes integer DEFAULT 10080 NOT NULL,
    next_audit_at timestamp(0) without time zone,
    last_audit_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    crawl_enabled boolean DEFAULT false,
    crawl_interval_days integer DEFAULT 7,
    next_crawl_at timestamp without time zone,
    last_crawl_at timestamp without time zone,
    max_pages integer DEFAULT 200,
    max_external_link_checks integer DEFAULT 50,
    sitemap_url character varying(2048),
    audit_config jsonb
);


--
-- Name: seo_monitors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_monitors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_monitors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_monitors_id_seq OWNED BY public.seo_monitors.id;


--
-- Name: seo_pages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_pages (
    id bigint NOT NULL,
    seo_audit_id bigint NOT NULL,
    site_id bigint NOT NULL,
    url character varying(2048) NOT NULL,
    url_hash character varying(64) NOT NULL,
    status_code smallint,
    depth smallint DEFAULT 0,
    content_type character varying(100),
    title character varying(1000),
    title_length smallint,
    meta_description text,
    meta_description_length smallint,
    h1_tags jsonb,
    heading_structure jsonb,
    word_count integer,
    image_count integer,
    images_without_alt integer,
    canonical_url character varying(2048),
    is_self_canonical boolean,
    meta_robots character varying(255),
    is_indexable boolean,
    in_sitemap boolean DEFAULT false,
    blocked_by_robots boolean DEFAULT false,
    internal_link_count integer DEFAULT 0,
    external_link_count integer DEFAULT 0,
    inbound_internal_links integer DEFAULT 0,
    redirect_target character varying(2048),
    redirect_chain_length smallint DEFAULT 0,
    page_size_bytes integer,
    ttfb_seconds double precision,
    structured_data_types jsonb,
    og_tags jsonb,
    twitter_tags jsonb,
    has_viewport_meta boolean,
    meta jsonb,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: seo_pages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_pages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_pages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_pages_id_seq OWNED BY public.seo_pages.id;


--
-- Name: site_cloudflare; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_cloudflare (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    cloudflare_connection_id bigint NOT NULL,
    zone_id character varying(255) NOT NULL,
    zone_name character varying(255) NOT NULL,
    plan_type character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    is_paused boolean DEFAULT false NOT NULL,
    ssl_mode character varying(255),
    cache_level character varying(255),
    connected_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    next_sync_at timestamp(0) without time zone,
    interval_minutes integer DEFAULT 360 NOT NULL,
    last_sync_at timestamp(0) without time zone
);


--
-- Name: site_cloudflare_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_cloudflare_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_cloudflare_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_cloudflare_id_seq OWNED BY public.site_cloudflare.id;


--
-- Name: site_contents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_contents (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    wp_post_id bigint NOT NULL,
    title character varying(255) NOT NULL,
    type character varying(255) DEFAULT 'post'::character varying,
    status character varying(255) DEFAULT 'publish'::character varying,
    url character varying(255),
    word_count integer DEFAULT 0,
    published_at timestamp without time zone,
    modified_at timestamp without time zone,
    author_name character varying(255),
    days_since_modified integer DEFAULT 0,
    is_stale boolean DEFAULT false,
    checked_at timestamp without time zone,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: site_contents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_contents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_contents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_contents_id_seq OWNED BY public.site_contents.id;


--
-- Name: site_crawls; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_crawls (
    id bigint NOT NULL,
    site_id bigint,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    pages_found integer DEFAULT 0 NOT NULL,
    pages_crawled integer DEFAULT 0 NOT NULL,
    pages_with_issues integer DEFAULT 0 NOT NULL,
    errors_count integer DEFAULT 0 NOT NULL,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    duration_seconds integer,
    config jsonb,
    summary jsonb,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    start_url character varying(2048)
);


--
-- Name: site_crawls_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_crawls_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_crawls_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_crawls_id_seq OWNED BY public.site_crawls.id;


--
-- Name: site_health_state; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_health_state (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    consecutive_failures integer DEFAULT 0 NOT NULL,
    last_failure_at timestamp(0) without time zone,
    last_failure_reason character varying(255),
    circuit_state character varying(255) DEFAULT 'closed'::character varying NOT NULL,
    circuit_opened_at timestamp(0) without time zone,
    circuit_breaks_last_24h integer DEFAULT 0 NOT NULL,
    circuit_breaks_reset_at timestamp(0) without time zone,
    is_monitoring_disabled boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_health_state_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_health_state_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_health_state_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_health_state_id_seq OWNED BY public.site_health_state.id;


--
-- Name: site_monthly_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_monthly_snapshots (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    year smallint NOT NULL,
    month smallint NOT NULL,
    uptime_avg_response_ms numeric(10,2),
    uptime_percentage numeric(6,3),
    uptime_down_checks integer,
    uptime_incidents_count integer,
    backups_total integer,
    backups_successful integer,
    backups_failed integer,
    updates_applied integer,
    security_avg_score numeric(5,2),
    performance_avg_desktop numeric(5,2),
    performance_avg_mobile numeric(5,2),
    analytics_users integer,
    analytics_sessions integer,
    analytics_pageviews integer,
    search_console_clicks integer,
    search_console_impressions integer,
    search_console_avg_position numeric(6,2),
    cloudflare_requests bigint,
    cloudflare_bandwidth_bytes bigint,
    cloudflare_cache_hit_ratio numeric(5,2),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    seo_score smallint,
    seo_issues_count integer
);


--
-- Name: site_monthly_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_monthly_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_monthly_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_monthly_snapshots_id_seq OWNED BY public.site_monthly_snapshots.id;


--
-- Name: site_plugin_conflicts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_plugin_conflicts (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    plugin_a_slug character varying(255) NOT NULL,
    plugin_b_slug character varying(255) NOT NULL,
    plugin_conflict_id bigint,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    detected_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_plugin_conflicts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_plugin_conflicts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_plugin_conflicts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_plugin_conflicts_id_seq OWNED BY public.site_plugin_conflicts.id;


--
-- Name: site_plugins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_plugins (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    file character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    version character varying(255),
    author character varying(255),
    author_uri character varying(255),
    plugin_uri character varying(255),
    description text,
    is_active boolean DEFAULT false NOT NULL,
    has_update boolean DEFAULT false NOT NULL,
    update_version character varying(255),
    requires_wp character varying(255),
    requires_php character varying(255),
    auto_update boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    wp_org_last_updated timestamp(0) without time zone,
    is_on_wp_org boolean,
    is_abandoned boolean DEFAULT false NOT NULL,
    is_closed boolean DEFAULT false NOT NULL,
    closed_reason character varying(255),
    abandoned_checked_at timestamp(0) without time zone,
    license_key text,
    license_expires_at timestamp(0) without time zone,
    license_status character varying(20)
);


--
-- Name: site_plugins_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_plugins_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_plugins_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_plugins_id_seq OWNED BY public.site_plugins.id;


--
-- Name: site_preset_modules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_preset_modules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_preset_modules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_preset_modules_id_seq OWNED BY public.maintenance_plan_modules.id;


--
-- Name: site_presets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_presets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_presets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_presets_id_seq OWNED BY public.maintenance_plans.id;


--
-- Name: site_report_configs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_report_configs (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    language character varying(255) DEFAULT 'en'::character varying NOT NULL,
    custom_notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_report_configs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_report_configs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_report_configs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_report_configs_id_seq OWNED BY public.site_report_configs.id;


--
-- Name: site_statuses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_statuses (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    color character varying(7) DEFAULT '#6b7280'::character varying NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_statuses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_statuses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_statuses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_statuses_id_seq OWNED BY public.site_statuses.id;


--
-- Name: site_themes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_themes (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    slug character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    version character varying(255),
    author character varying(255),
    author_uri character varying(255),
    description text,
    is_active boolean DEFAULT false NOT NULL,
    is_child_theme boolean DEFAULT false NOT NULL,
    parent_theme character varying(255),
    has_update boolean DEFAULT false NOT NULL,
    update_version character varying(255),
    screenshot_url character varying(255),
    auto_update boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_themes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_themes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_themes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_themes_id_seq OWNED BY public.site_themes.id;


--
-- Name: site_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_users (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    wp_user_id bigint NOT NULL,
    username character varying(255) NOT NULL,
    email character varying(255),
    display_name character varying(255),
    role character varying(255),
    avatar_url character varying(255),
    posts_count integer DEFAULT 0 NOT NULL,
    registered_at timestamp(0) without time zone,
    last_login_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    synced_at timestamp(0) without time zone,
    orders_count integer DEFAULT 0 NOT NULL
);


--
-- Name: site_users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_users_id_seq OWNED BY public.site_users.id;


--
-- Name: sites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sites (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    url character varying(255) NOT NULL,
    client_id bigint,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    health_score integer,
    wp_version character varying(255),
    php_version character varying(255),
    server_software character varying(255),
    is_multisite boolean DEFAULT false NOT NULL,
    uptime_percentage numeric(5,2),
    is_up boolean DEFAULT true NOT NULL,
    pending_updates_count integer DEFAULT 0 NOT NULL,
    backup_ok boolean DEFAULT false NOT NULL,
    last_backup_at timestamp(0) without time zone,
    notes text,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    type character varying(255) DEFAULT 'wordpress'::character varying NOT NULL,
    api_key text,
    api_secret text,
    api_endpoint character varying(255),
    is_connected boolean DEFAULT false NOT NULL,
    last_synced_at timestamp(0) without time zone,
    db_size_mb numeric(10,2),
    uploads_size_mb numeric(10,2),
    core_update_version character varying(255),
    site_status_id bigint,
    sort_order integer DEFAULT 0 NOT NULL,
    favicon_path character varying(255),
    screenshot_path character varying(255),
    user_id bigint,
    maintenance_plan_id bigint,
    is_plan_customized boolean DEFAULT false NOT NULL,
    security_hardening_score smallint,
    backup_capabilities jsonb,
    backup_capabilities_checked_at timestamp without time zone,
    custom_login_slug character varying(50),
    connector_version character varying(255),
    report_template_id bigint,
    wp_admin_user_id bigint,
    is_prospect boolean DEFAULT false NOT NULL
);


--
-- Name: sites_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sites_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sites_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sites_id_seq OWNED BY public.sites.id;


--
-- Name: status_page_incident_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.status_page_incident_templates (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    severity character varying(255) DEFAULT 'minor'::character varying NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: status_page_incident_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.status_page_incident_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: status_page_incident_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.status_page_incident_templates_id_seq OWNED BY public.status_page_incident_templates.id;


--
-- Name: status_page_incident_updates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.status_page_incident_updates (
    id bigint NOT NULL,
    status_page_incident_id bigint NOT NULL,
    status character varying(255) NOT NULL,
    message text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: status_page_incident_updates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.status_page_incident_updates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: status_page_incident_updates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.status_page_incident_updates_id_seq OWNED BY public.status_page_incident_updates.id;


--
-- Name: status_page_incidents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.status_page_incidents (
    id bigint NOT NULL,
    status_page_id bigint NOT NULL,
    site_id bigint,
    title character varying(255) NOT NULL,
    description text,
    status character varying(255) DEFAULT 'investigating'::character varying NOT NULL,
    severity character varying(255) DEFAULT 'minor'::character varying NOT NULL,
    is_scheduled boolean DEFAULT false NOT NULL,
    scheduled_start_at timestamp(0) without time zone,
    scheduled_end_at timestamp(0) without time zone,
    started_at timestamp(0) without time zone,
    resolved_at timestamp(0) without time zone,
    auto_created boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: status_page_incidents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.status_page_incidents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: status_page_incidents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.status_page_incidents_id_seq OWNED BY public.status_page_incidents.id;


--
-- Name: status_page_sites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.status_page_sites (
    id bigint NOT NULL,
    status_page_id bigint NOT NULL,
    site_id bigint NOT NULL,
    display_name character varying(255),
    sort_order integer DEFAULT 0 NOT NULL,
    is_visible boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: status_page_sites_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.status_page_sites_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: status_page_sites_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.status_page_sites_id_seq OWNED BY public.status_page_sites.id;


--
-- Name: status_pages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.status_pages (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    client_id bigint,
    slug character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    logo_url character varying(255),
    primary_color character varying(255) DEFAULT '#7C3AED'::character varying NOT NULL,
    custom_domain character varying(255),
    is_public boolean DEFAULT true NOT NULL,
    show_uptime_percentage boolean DEFAULT true NOT NULL,
    show_response_time boolean DEFAULT false NOT NULL,
    show_incident_history boolean DEFAULT true NOT NULL,
    incident_history_days integer DEFAULT 90 NOT NULL,
    auto_incidents boolean DEFAULT true NOT NULL,
    password_hash character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    sla_target numeric(5,2),
    show_sla boolean DEFAULT false NOT NULL
);


--
-- Name: status_pages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.status_pages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: status_pages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.status_pages_id_seq OWNED BY public.status_pages.id;


--
-- Name: storage_destinations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.storage_destinations (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    type character varying(255) NOT NULL,
    config jsonb,
    is_default boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    used_bytes bigint DEFAULT '0'::bigint NOT NULL,
    quota_bytes bigint,
    last_tested_at timestamp(0) without time zone,
    last_test_passed boolean,
    last_test_error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: storage_destinations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.storage_destinations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: storage_destinations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.storage_destinations_id_seq OWNED BY public.storage_destinations.id;


--
-- Name: theme_file_checks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.theme_file_checks (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    theme_slug character varying(255) NOT NULL,
    theme_version character varying(255),
    total_files integer DEFAULT 0,
    modified_count integer DEFAULT 0,
    unknown_count integer DEFAULT 0,
    modified_files jsonb,
    unknown_files jsonb,
    baseline_hashes jsonb,
    status character varying(255) NOT NULL,
    error_message text,
    checked_at timestamp without time zone,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: theme_file_checks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.theme_file_checks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: theme_file_checks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.theme_file_checks_id_seq OWNED BY public.theme_file_checks.id;


--
-- Name: tracked_keywords; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tracked_keywords (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    keyword character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_brand boolean DEFAULT false NOT NULL,
    landing_page_url text
);


--
-- Name: tracked_keywords_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tracked_keywords_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tracked_keywords_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tracked_keywords_id_seq OWNED BY public.tracked_keywords.id;


--
-- Name: update_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.update_logs (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    user_id bigint,
    type character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255),
    from_version character varying(255),
    to_version character varying(255),
    success boolean DEFAULT true NOT NULL,
    error_message text,
    performed_at timestamp(0) without time zone NOT NULL
);


--
-- Name: update_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.update_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: update_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.update_logs_id_seq OWNED BY public.update_logs.id;


--
-- Name: uptime_checks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.uptime_checks (
    id bigint NOT NULL,
    monitor_id bigint NOT NULL,
    is_up boolean NOT NULL,
    response_time integer,
    status_code smallint,
    failure_reason character varying(255),
    keyword_found boolean,
    ssl_expires_at timestamp(0) without time zone,
    checked_at timestamp(0) without time zone NOT NULL,
    location character varying(50) DEFAULT 'primary'::character varying NOT NULL
);


--
-- Name: uptime_checks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.uptime_checks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: uptime_checks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.uptime_checks_id_seq OWNED BY public.uptime_checks.id;


--
-- Name: uptime_incidents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.uptime_incidents (
    id bigint NOT NULL,
    monitor_id bigint NOT NULL,
    status character varying(255) DEFAULT 'ongoing'::character varying NOT NULL,
    cause character varying(255),
    started_at timestamp(0) without time zone NOT NULL,
    resolved_at timestamp(0) without time zone,
    notified_via jsonb,
    notified_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: uptime_incidents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.uptime_incidents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: uptime_incidents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.uptime_incidents_id_seq OWNED BY public.uptime_incidents.id;


--
-- Name: uptime_monitors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.uptime_monitors (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    type character varying(255) DEFAULT 'http'::character varying NOT NULL,
    url character varying(255) NOT NULL,
    timeout integer DEFAULT 30 NOT NULL,
    http_method character varying(255) DEFAULT 'GET'::character varying NOT NULL,
    http_headers jsonb,
    http_body text,
    accepted_status_codes jsonb,
    follow_redirects boolean DEFAULT true NOT NULL,
    auth_type character varying(255),
    auth_username character varying(255),
    auth_password text,
    auth_token text,
    keyword character varying(255),
    keyword_type character varying(255),
    keyword_case_sensitive boolean DEFAULT false NOT NULL,
    alert_after_failures integer DEFAULT 3 NOT NULL,
    alert_contacts jsonb,
    consecutive_failures integer DEFAULT 0 NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    current_state character varying(255) DEFAULT 'unknown'::character varying NOT NULL,
    last_checked_at timestamp(0) without time zone,
    next_check_at timestamp(0) without time zone,
    last_state_change_at timestamp(0) without time zone,
    uptime_24h numeric(6,3),
    uptime_7d numeric(6,3),
    uptime_30d numeric(6,3),
    uptime_365d numeric(6,3),
    avg_response_time integer,
    last_response_time integer,
    last_failure_reason character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    interval_minutes integer DEFAULT 5 NOT NULL,
    maintenance_starts_at timestamp(0) without time zone,
    maintenance_ends_at timestamp(0) without time zone,
    maintenance_reason character varying(255),
    check_locations jsonb,
    require_all_locations_down boolean DEFAULT false NOT NULL
);


--
-- Name: uptime_monitors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.uptime_monitors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: uptime_monitors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.uptime_monitors_id_seq OWNED BY public.uptime_monitors.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    timezone character varying(255) DEFAULT 'UTC'::character varying NOT NULL,
    date_format character varying(255) DEFAULT 'M d, Y'::character varying NOT NULL,
    language character varying(255) DEFAULT 'en'::character varying NOT NULL,
    two_factor_enabled boolean DEFAULT false NOT NULL,
    two_factor_secret text,
    two_factor_recovery_codes text,
    avatar_path character varying(255),
    is_admin boolean DEFAULT false NOT NULL,
    role character varying(20) DEFAULT 'viewer'::character varying NOT NULL,
    google_id character varying(255),
    theme character varying(10) DEFAULT 'light'::character varying
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: vulnerability_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vulnerability_alerts (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    software_type character varying(255) NOT NULL,
    software_slug character varying(255) NOT NULL,
    installed_version character varying(255),
    vulnerability_id character varying(255),
    severity character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    fixed_in_version character varying(255),
    "references" jsonb,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    detected_at timestamp(0) without time zone,
    fixed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: vulnerability_alerts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.vulnerability_alerts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vulnerability_alerts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.vulnerability_alerts_id_seq OWNED BY public.vulnerability_alerts.id;


--
-- Name: activity_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_logs ALTER COLUMN id SET DEFAULT nextval('public.activity_logs_id_seq'::regclass);


--
-- Name: analytics_cache id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_cache ALTER COLUMN id SET DEFAULT nextval('public.analytics_cache_id_seq'::regclass);


--
-- Name: analytics_connections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_connections ALTER COLUMN id SET DEFAULT nextval('public.analytics_connections_id_seq'::regclass);


--
-- Name: app_backup_configs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_backup_configs ALTER COLUMN id SET DEFAULT nextval('public.app_backup_configs_id_seq'::regclass);


--
-- Name: app_backups id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_backups ALTER COLUMN id SET DEFAULT nextval('public.app_backups_id_seq'::regclass);


--
-- Name: app_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_settings ALTER COLUMN id SET DEFAULT nextval('public.app_settings_id_seq'::regclass);


--
-- Name: backlink_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backlink_snapshots ALTER COLUMN id SET DEFAULT nextval('public.backlink_snapshots_id_seq'::regclass);


--
-- Name: backlinks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backlinks ALTER COLUMN id SET DEFAULT nextval('public.backlinks_id_seq'::regclass);


--
-- Name: backup_configs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_configs ALTER COLUMN id SET DEFAULT nextval('public.backup_configs_id_seq'::regclass);


--
-- Name: backups id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backups ALTER COLUMN id SET DEFAULT nextval('public.backups_id_seq'::regclass);


--
-- Name: client_costs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_costs ALTER COLUMN id SET DEFAULT nextval('public.client_costs_id_seq'::regclass);


--
-- Name: client_revenues id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_revenues ALTER COLUMN id SET DEFAULT nextval('public.client_revenues_id_seq'::regclass);


--
-- Name: client_user id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_user ALTER COLUMN id SET DEFAULT nextval('public.client_user_id_seq'::regclass);


--
-- Name: clients id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients ALTER COLUMN id SET DEFAULT nextval('public.clients_id_seq'::regclass);


--
-- Name: cloudflare_cache_purges id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloudflare_cache_purges ALTER COLUMN id SET DEFAULT nextval('public.cloudflare_cache_purges_id_seq'::regclass);


--
-- Name: cloudflare_connections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloudflare_connections ALTER COLUMN id SET DEFAULT nextval('public.cloudflare_connections_id_seq'::regclass);


--
-- Name: competitor_keyword_positions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.competitor_keyword_positions ALTER COLUMN id SET DEFAULT nextval('public.competitor_keyword_positions_id_seq'::regclass);


--
-- Name: competitor_sites id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.competitor_sites ALTER COLUMN id SET DEFAULT nextval('public.competitor_sites_id_seq'::regclass);


--
-- Name: core_file_checks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_file_checks ALTER COLUMN id SET DEFAULT nextval('public.core_file_checks_id_seq'::regclass);


--
-- Name: crawled_pages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.crawled_pages ALTER COLUMN id SET DEFAULT nextval('public.crawled_pages_id_seq'::regclass);


--
-- Name: dashboard_widgets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_widgets ALTER COLUMN id SET DEFAULT nextval('public.dashboard_widgets_id_seq'::regclass);


--
-- Name: database_cleanup_configs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.database_cleanup_configs ALTER COLUMN id SET DEFAULT nextval('public.database_cleanup_configs_id_seq'::regclass);


--
-- Name: database_cleanups id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.database_cleanups ALTER COLUMN id SET DEFAULT nextval('public.database_cleanups_id_seq'::regclass);


--
-- Name: database_health_checks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.database_health_checks ALTER COLUMN id SET DEFAULT nextval('public.database_health_checks_id_seq'::regclass);


--
-- Name: dns_changes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_changes ALTER COLUMN id SET DEFAULT nextval('public.dns_changes_id_seq'::regclass);


--
-- Name: dns_monitors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_monitors ALTER COLUMN id SET DEFAULT nextval('public.dns_monitors_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: google_connections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.google_connections ALTER COLUMN id SET DEFAULT nextval('public.google_connections_id_seq'::regclass);


--
-- Name: health_score_history id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.health_score_history ALTER COLUMN id SET DEFAULT nextval('public.health_score_history_id_seq'::regclass);


--
-- Name: in_app_notifications id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.in_app_notifications ALTER COLUMN id SET DEFAULT nextval('public.in_app_notifications_id_seq'::regclass);


--
-- Name: invitations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invitations ALTER COLUMN id SET DEFAULT nextval('public.invitations_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: keyword_page_mappings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_page_mappings ALTER COLUMN id SET DEFAULT nextval('public.keyword_page_mappings_id_seq'::regclass);


--
-- Name: keyword_positions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_positions ALTER COLUMN id SET DEFAULT nextval('public.keyword_positions_id_seq'::regclass);


--
-- Name: keyword_research_results id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_research_results ALTER COLUMN id SET DEFAULT nextval('public.keyword_research_results_id_seq'::regclass);


--
-- Name: maintenance_plan_modules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_plan_modules ALTER COLUMN id SET DEFAULT nextval('public.site_preset_modules_id_seq'::regclass);


--
-- Name: maintenance_plans id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_plans ALTER COLUMN id SET DEFAULT nextval('public.site_presets_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: notification_channels id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_channels ALTER COLUMN id SET DEFAULT nextval('public.notification_channels_id_seq'::regclass);


--
-- Name: notification_escalation_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_escalation_rules ALTER COLUMN id SET DEFAULT nextval('public.notification_escalation_rules_id_seq'::regclass);


--
-- Name: notification_event_preferences id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_event_preferences ALTER COLUMN id SET DEFAULT nextval('public.notification_event_preferences_id_seq'::regclass);


--
-- Name: notification_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_logs ALTER COLUMN id SET DEFAULT nextval('public.notification_logs_id_seq'::regclass);


--
-- Name: notification_templates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates ALTER COLUMN id SET DEFAULT nextval('public.notification_templates_id_seq'::regclass);


--
-- Name: performance_monitors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.performance_monitors ALTER COLUMN id SET DEFAULT nextval('public.performance_monitors_id_seq'::regclass);


--
-- Name: performance_pages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.performance_pages ALTER COLUMN id SET DEFAULT nextval('public.performance_pages_id_seq'::regclass);


--
-- Name: performance_tests id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.performance_tests ALTER COLUMN id SET DEFAULT nextval('public.performance_tests_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: php_error_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.php_error_logs ALTER COLUMN id SET DEFAULT nextval('public.php_error_logs_id_seq'::regclass);


--
-- Name: plugin_conflicts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plugin_conflicts ALTER COLUMN id SET DEFAULT nextval('public.plugin_conflicts_id_seq'::regclass);


--
-- Name: recommendation_templates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recommendation_templates ALTER COLUMN id SET DEFAULT nextval('public.recommendation_templates_id_seq'::regclass);


--
-- Name: report_recommendations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_recommendations ALTER COLUMN id SET DEFAULT nextval('public.report_recommendations_id_seq'::regclass);


--
-- Name: report_schedules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_schedules ALTER COLUMN id SET DEFAULT nextval('public.report_schedules_id_seq'::regclass);


--
-- Name: report_templates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_templates ALTER COLUMN id SET DEFAULT nextval('public.report_templates_id_seq'::regclass);


--
-- Name: reports id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reports ALTER COLUMN id SET DEFAULT nextval('public.reports_id_seq'::regclass);


--
-- Name: rollback_points id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rollback_points ALTER COLUMN id SET DEFAULT nextval('public.rollback_points_id_seq'::regclass);


--
-- Name: safe_updates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.safe_updates ALTER COLUMN id SET DEFAULT nextval('public.safe_updates_id_seq'::regclass);


--
-- Name: search_console_cache id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.search_console_cache ALTER COLUMN id SET DEFAULT nextval('public.search_console_cache_id_seq'::regclass);


--
-- Name: search_console_connections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.search_console_connections ALTER COLUMN id SET DEFAULT nextval('public.search_console_connections_id_seq'::regclass);


--
-- Name: security_activity_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_activity_logs ALTER COLUMN id SET DEFAULT nextval('public.security_activity_logs_id_seq'::regclass);


--
-- Name: security_banned_ips id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_banned_ips ALTER COLUMN id SET DEFAULT nextval('public.security_banned_ips_id_seq'::regclass);


--
-- Name: security_commands id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_commands ALTER COLUMN id SET DEFAULT nextval('public.security_commands_id_seq'::regclass);


--
-- Name: security_ip_lists id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_ip_lists ALTER COLUMN id SET DEFAULT nextval('public.security_ip_lists_id_seq'::regclass);


--
-- Name: security_issues id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_issues ALTER COLUMN id SET DEFAULT nextval('public.security_issues_id_seq'::regclass);


--
-- Name: security_monitors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_monitors ALTER COLUMN id SET DEFAULT nextval('public.security_monitors_id_seq'::regclass);


--
-- Name: security_preset_site id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_preset_site ALTER COLUMN id SET DEFAULT nextval('public.security_preset_site_id_seq'::regclass);


--
-- Name: security_presets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_presets ALTER COLUMN id SET DEFAULT nextval('public.security_presets_id_seq'::regclass);


--
-- Name: security_recommendations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_recommendations ALTER COLUMN id SET DEFAULT nextval('public.security_recommendations_id_seq'::regclass);


--
-- Name: security_scans id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_scans ALTER COLUMN id SET DEFAULT nextval('public.security_scans_id_seq'::regclass);


--
-- Name: security_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_settings ALTER COLUMN id SET DEFAULT nextval('public.security_settings_id_seq'::regclass);


--
-- Name: seo_alert_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_alert_rules ALTER COLUMN id SET DEFAULT nextval('public.seo_alert_rules_id_seq'::regclass);


--
-- Name: seo_audits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_audits ALTER COLUMN id SET DEFAULT nextval('public.seo_audits_id_seq'::regclass);


--
-- Name: seo_content_revisions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_content_revisions ALTER COLUMN id SET DEFAULT nextval('public.seo_content_revisions_id_seq'::regclass);


--
-- Name: seo_contents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_contents ALTER COLUMN id SET DEFAULT nextval('public.seo_contents_id_seq'::regclass);


--
-- Name: seo_images id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_images ALTER COLUMN id SET DEFAULT nextval('public.seo_images_id_seq'::regclass);


--
-- Name: seo_issues id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_issues ALTER COLUMN id SET DEFAULT nextval('public.seo_issues_id_seq'::regclass);


--
-- Name: seo_keyword_rankings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_keyword_rankings ALTER COLUMN id SET DEFAULT nextval('public.seo_keyword_rankings_id_seq'::regclass);


--
-- Name: seo_links id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_links ALTER COLUMN id SET DEFAULT nextval('public.seo_links_id_seq'::regclass);


--
-- Name: seo_monitors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_monitors ALTER COLUMN id SET DEFAULT nextval('public.seo_monitors_id_seq'::regclass);


--
-- Name: seo_pages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_pages ALTER COLUMN id SET DEFAULT nextval('public.seo_pages_id_seq'::regclass);


--
-- Name: site_cloudflare id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_cloudflare ALTER COLUMN id SET DEFAULT nextval('public.site_cloudflare_id_seq'::regclass);


--
-- Name: site_contents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_contents ALTER COLUMN id SET DEFAULT nextval('public.site_contents_id_seq'::regclass);


--
-- Name: site_crawls id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_crawls ALTER COLUMN id SET DEFAULT nextval('public.site_crawls_id_seq'::regclass);


--
-- Name: site_health_state id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_health_state ALTER COLUMN id SET DEFAULT nextval('public.site_health_state_id_seq'::regclass);


--
-- Name: site_monthly_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_monthly_snapshots ALTER COLUMN id SET DEFAULT nextval('public.site_monthly_snapshots_id_seq'::regclass);


--
-- Name: site_plugin_conflicts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_plugin_conflicts ALTER COLUMN id SET DEFAULT nextval('public.site_plugin_conflicts_id_seq'::regclass);


--
-- Name: site_plugins id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_plugins ALTER COLUMN id SET DEFAULT nextval('public.site_plugins_id_seq'::regclass);


--
-- Name: site_report_configs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_report_configs ALTER COLUMN id SET DEFAULT nextval('public.site_report_configs_id_seq'::regclass);


--
-- Name: site_statuses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_statuses ALTER COLUMN id SET DEFAULT nextval('public.site_statuses_id_seq'::regclass);


--
-- Name: site_themes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_themes ALTER COLUMN id SET DEFAULT nextval('public.site_themes_id_seq'::regclass);


--
-- Name: site_users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_users ALTER COLUMN id SET DEFAULT nextval('public.site_users_id_seq'::regclass);


--
-- Name: sites id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites ALTER COLUMN id SET DEFAULT nextval('public.sites_id_seq'::regclass);


--
-- Name: status_page_incident_templates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_incident_templates ALTER COLUMN id SET DEFAULT nextval('public.status_page_incident_templates_id_seq'::regclass);


--
-- Name: status_page_incident_updates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_incident_updates ALTER COLUMN id SET DEFAULT nextval('public.status_page_incident_updates_id_seq'::regclass);


--
-- Name: status_page_incidents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_incidents ALTER COLUMN id SET DEFAULT nextval('public.status_page_incidents_id_seq'::regclass);


--
-- Name: status_page_sites id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_sites ALTER COLUMN id SET DEFAULT nextval('public.status_page_sites_id_seq'::regclass);


--
-- Name: status_pages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_pages ALTER COLUMN id SET DEFAULT nextval('public.status_pages_id_seq'::regclass);


--
-- Name: storage_destinations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.storage_destinations ALTER COLUMN id SET DEFAULT nextval('public.storage_destinations_id_seq'::regclass);


--
-- Name: theme_file_checks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.theme_file_checks ALTER COLUMN id SET DEFAULT nextval('public.theme_file_checks_id_seq'::regclass);


--
-- Name: tracked_keywords id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tracked_keywords ALTER COLUMN id SET DEFAULT nextval('public.tracked_keywords_id_seq'::regclass);


--
-- Name: update_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.update_logs ALTER COLUMN id SET DEFAULT nextval('public.update_logs_id_seq'::regclass);


--
-- Name: uptime_checks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uptime_checks ALTER COLUMN id SET DEFAULT nextval('public.uptime_checks_id_seq'::regclass);


--
-- Name: uptime_incidents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uptime_incidents ALTER COLUMN id SET DEFAULT nextval('public.uptime_incidents_id_seq'::regclass);


--
-- Name: uptime_monitors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uptime_monitors ALTER COLUMN id SET DEFAULT nextval('public.uptime_monitors_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: vulnerability_alerts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vulnerability_alerts ALTER COLUMN id SET DEFAULT nextval('public.vulnerability_alerts_id_seq'::regclass);


--
-- Name: activity_logs activity_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_logs
    ADD CONSTRAINT activity_logs_pkey PRIMARY KEY (id);


--
-- Name: analytics_cache analytics_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_cache
    ADD CONSTRAINT analytics_cache_pkey PRIMARY KEY (id);


--
-- Name: analytics_connections analytics_connections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_connections
    ADD CONSTRAINT analytics_connections_pkey PRIMARY KEY (id);


--
-- Name: analytics_connections analytics_connections_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_connections
    ADD CONSTRAINT analytics_connections_site_id_unique UNIQUE (site_id);


--
-- Name: app_backup_configs app_backup_configs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_backup_configs
    ADD CONSTRAINT app_backup_configs_pkey PRIMARY KEY (id);


--
-- Name: app_backups app_backups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_backups
    ADD CONSTRAINT app_backups_pkey PRIMARY KEY (id);


--
-- Name: app_settings app_settings_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_settings
    ADD CONSTRAINT app_settings_key_unique UNIQUE (key);


--
-- Name: app_settings app_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_settings
    ADD CONSTRAINT app_settings_pkey PRIMARY KEY (id);


--
-- Name: backlink_snapshots backlink_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backlink_snapshots
    ADD CONSTRAINT backlink_snapshots_pkey PRIMARY KEY (id);


--
-- Name: backlink_snapshots backlink_snapshots_site_id_date_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backlink_snapshots
    ADD CONSTRAINT backlink_snapshots_site_id_date_unique UNIQUE (site_id, date);


--
-- Name: backlinks backlinks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backlinks
    ADD CONSTRAINT backlinks_pkey PRIMARY KEY (id);


--
-- Name: backup_configs backup_configs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_configs
    ADD CONSTRAINT backup_configs_pkey PRIMARY KEY (id);


--
-- Name: backups backups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backups
    ADD CONSTRAINT backups_pkey PRIMARY KEY (id);


--
-- Name: client_costs client_costs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_costs
    ADD CONSTRAINT client_costs_pkey PRIMARY KEY (id);


--
-- Name: client_revenues client_revenues_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_revenues
    ADD CONSTRAINT client_revenues_pkey PRIMARY KEY (id);


--
-- Name: client_user client_user_client_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_user
    ADD CONSTRAINT client_user_client_id_user_id_unique UNIQUE (client_id, user_id);


--
-- Name: client_user client_user_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_user
    ADD CONSTRAINT client_user_pkey PRIMARY KEY (id);


--
-- Name: clients clients_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_pkey PRIMARY KEY (id);


--
-- Name: clients clients_portal_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_portal_token_unique UNIQUE (portal_token);


--
-- Name: cloudflare_cache_purges cloudflare_cache_purges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloudflare_cache_purges
    ADD CONSTRAINT cloudflare_cache_purges_pkey PRIMARY KEY (id);


--
-- Name: cloudflare_connections cloudflare_connections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloudflare_connections
    ADD CONSTRAINT cloudflare_connections_pkey PRIMARY KEY (id);


--
-- Name: competitor_keyword_positions competitor_keyword_positions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.competitor_keyword_positions
    ADD CONSTRAINT competitor_keyword_positions_pkey PRIMARY KEY (id);


--
-- Name: competitor_sites competitor_sites_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.competitor_sites
    ADD CONSTRAINT competitor_sites_pkey PRIMARY KEY (id);


--
-- Name: competitor_sites competitor_sites_site_id_competitor_url_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.competitor_sites
    ADD CONSTRAINT competitor_sites_site_id_competitor_url_unique UNIQUE (site_id, competitor_url);


--
-- Name: core_file_checks core_file_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_file_checks
    ADD CONSTRAINT core_file_checks_pkey PRIMARY KEY (id);


--
-- Name: crawled_pages crawled_pages_crawl_url_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.crawled_pages
    ADD CONSTRAINT crawled_pages_crawl_url_unique UNIQUE (site_crawl_id, url);


--
-- Name: crawled_pages crawled_pages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.crawled_pages
    ADD CONSTRAINT crawled_pages_pkey PRIMARY KEY (id);


--
-- Name: dashboard_widgets dashboard_widgets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_widgets
    ADD CONSTRAINT dashboard_widgets_pkey PRIMARY KEY (id);


--
-- Name: database_cleanup_configs database_cleanup_configs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.database_cleanup_configs
    ADD CONSTRAINT database_cleanup_configs_pkey PRIMARY KEY (id);


--
-- Name: database_cleanup_configs database_cleanup_configs_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.database_cleanup_configs
    ADD CONSTRAINT database_cleanup_configs_site_id_unique UNIQUE (site_id);


--
-- Name: database_cleanups database_cleanups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.database_cleanups
    ADD CONSTRAINT database_cleanups_pkey PRIMARY KEY (id);


--
-- Name: database_health_checks database_health_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.database_health_checks
    ADD CONSTRAINT database_health_checks_pkey PRIMARY KEY (id);


--
-- Name: dns_changes dns_changes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_changes
    ADD CONSTRAINT dns_changes_pkey PRIMARY KEY (id);


--
-- Name: dns_monitors dns_monitors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_monitors
    ADD CONSTRAINT dns_monitors_pkey PRIMARY KEY (id);


--
-- Name: dns_monitors dns_monitors_site_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_monitors
    ADD CONSTRAINT dns_monitors_site_id_key UNIQUE (site_id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: google_connections google_connections_google_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.google_connections
    ADD CONSTRAINT google_connections_google_id_unique UNIQUE (google_id);


--
-- Name: google_connections google_connections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.google_connections
    ADD CONSTRAINT google_connections_pkey PRIMARY KEY (id);


--
-- Name: health_score_history health_score_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.health_score_history
    ADD CONSTRAINT health_score_history_pkey PRIMARY KEY (id);


--
-- Name: health_score_history health_score_history_site_id_recorded_at_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.health_score_history
    ADD CONSTRAINT health_score_history_site_id_recorded_at_key UNIQUE (site_id, recorded_at);


--
-- Name: in_app_notifications in_app_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.in_app_notifications
    ADD CONSTRAINT in_app_notifications_pkey PRIMARY KEY (id);


--
-- Name: invitations invitations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invitations
    ADD CONSTRAINT invitations_pkey PRIMARY KEY (id);


--
-- Name: invitations invitations_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invitations
    ADD CONSTRAINT invitations_token_unique UNIQUE (token);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: keyword_page_mappings keyword_page_mappings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_page_mappings
    ADD CONSTRAINT keyword_page_mappings_pkey PRIMARY KEY (id);


--
-- Name: keyword_page_mappings keyword_page_mappings_tracked_keyword_id_url_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_page_mappings
    ADD CONSTRAINT keyword_page_mappings_tracked_keyword_id_url_unique UNIQUE (tracked_keyword_id, url);


--
-- Name: keyword_positions keyword_positions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_positions
    ADD CONSTRAINT keyword_positions_pkey PRIMARY KEY (id);


--
-- Name: keyword_positions keyword_positions_tracked_keyword_id_date_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_positions
    ADD CONSTRAINT keyword_positions_tracked_keyword_id_date_unique UNIQUE (tracked_keyword_id, date);


--
-- Name: keyword_research_results keyword_research_results_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_research_results
    ADD CONSTRAINT keyword_research_results_pkey PRIMARY KEY (id);


--
-- Name: maintenance_plan_modules maintenance_plan_modules_maintenance_plan_id_module_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_plan_modules
    ADD CONSTRAINT maintenance_plan_modules_maintenance_plan_id_module_key_unique UNIQUE (maintenance_plan_id, module_key);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: notification_channels notification_channels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_channels
    ADD CONSTRAINT notification_channels_pkey PRIMARY KEY (id);


--
-- Name: notification_escalation_rules notification_escalation_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_escalation_rules
    ADD CONSTRAINT notification_escalation_rules_pkey PRIMARY KEY (id);


--
-- Name: notification_event_preferences notification_event_preference_user_id_notification_channel__key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_event_preferences
    ADD CONSTRAINT notification_event_preference_user_id_notification_channel__key UNIQUE (user_id, notification_channel_id, event);


--
-- Name: notification_event_preferences notification_event_preferences_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_event_preferences
    ADD CONSTRAINT notification_event_preferences_pkey PRIMARY KEY (id);


--
-- Name: notification_logs notification_logs_ack_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_logs
    ADD CONSTRAINT notification_logs_ack_token_unique UNIQUE (ack_token);


--
-- Name: notification_logs notification_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_logs
    ADD CONSTRAINT notification_logs_pkey PRIMARY KEY (id);


--
-- Name: notification_templates notification_templates_event_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates
    ADD CONSTRAINT notification_templates_event_unique UNIQUE (event);


--
-- Name: notification_templates notification_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates
    ADD CONSTRAINT notification_templates_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: performance_monitors performance_monitors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.performance_monitors
    ADD CONSTRAINT performance_monitors_pkey PRIMARY KEY (id);


--
-- Name: performance_pages performance_pages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.performance_pages
    ADD CONSTRAINT performance_pages_pkey PRIMARY KEY (id);


--
-- Name: performance_tests performance_tests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.performance_tests
    ADD CONSTRAINT performance_tests_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: php_error_logs php_error_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.php_error_logs
    ADD CONSTRAINT php_error_logs_pkey PRIMARY KEY (id);


--
-- Name: php_error_logs php_error_logs_site_id_message_hash_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.php_error_logs
    ADD CONSTRAINT php_error_logs_site_id_message_hash_key UNIQUE (site_id, message_hash);


--
-- Name: plugin_conflicts plugin_conflicts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plugin_conflicts
    ADD CONSTRAINT plugin_conflicts_pkey PRIMARY KEY (id);


--
-- Name: recommendation_templates recommendation_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recommendation_templates
    ADD CONSTRAINT recommendation_templates_pkey PRIMARY KEY (id);


--
-- Name: report_recommendations report_recommendations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_recommendations
    ADD CONSTRAINT report_recommendations_pkey PRIMARY KEY (id);


--
-- Name: report_schedules report_schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_schedules
    ADD CONSTRAINT report_schedules_pkey PRIMARY KEY (id);


--
-- Name: report_templates report_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_templates
    ADD CONSTRAINT report_templates_pkey PRIMARY KEY (id);


--
-- Name: reports reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reports
    ADD CONSTRAINT reports_pkey PRIMARY KEY (id);


--
-- Name: rollback_points rollback_points_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rollback_points
    ADD CONSTRAINT rollback_points_pkey PRIMARY KEY (id);


--
-- Name: safe_updates safe_updates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.safe_updates
    ADD CONSTRAINT safe_updates_pkey PRIMARY KEY (id);


--
-- Name: search_console_cache search_console_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.search_console_cache
    ADD CONSTRAINT search_console_cache_pkey PRIMARY KEY (id);


--
-- Name: search_console_connections search_console_connections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.search_console_connections
    ADD CONSTRAINT search_console_connections_pkey PRIMARY KEY (id);


--
-- Name: search_console_connections search_console_connections_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.search_console_connections
    ADD CONSTRAINT search_console_connections_site_id_unique UNIQUE (site_id);


--
-- Name: security_activity_logs security_activity_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_activity_logs
    ADD CONSTRAINT security_activity_logs_pkey PRIMARY KEY (id);


--
-- Name: security_banned_ips security_banned_ips_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_banned_ips
    ADD CONSTRAINT security_banned_ips_pkey PRIMARY KEY (id);


--
-- Name: security_commands security_commands_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_commands
    ADD CONSTRAINT security_commands_pkey PRIMARY KEY (id);


--
-- Name: security_ip_lists security_ip_lists_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_ip_lists
    ADD CONSTRAINT security_ip_lists_pkey PRIMARY KEY (id);


--
-- Name: security_issues security_issues_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_issues
    ADD CONSTRAINT security_issues_pkey PRIMARY KEY (id);


--
-- Name: security_issues security_issues_site_id_type_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_issues
    ADD CONSTRAINT security_issues_site_id_type_unique UNIQUE (site_id, type);


--
-- Name: security_monitors security_monitors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_monitors
    ADD CONSTRAINT security_monitors_pkey PRIMARY KEY (id);


--
-- Name: security_monitors security_monitors_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_monitors
    ADD CONSTRAINT security_monitors_site_id_unique UNIQUE (site_id);


--
-- Name: security_preset_site security_preset_site_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_preset_site
    ADD CONSTRAINT security_preset_site_pkey PRIMARY KEY (id);


--
-- Name: security_preset_site security_preset_site_security_preset_id_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_preset_site
    ADD CONSTRAINT security_preset_site_security_preset_id_site_id_unique UNIQUE (security_preset_id, site_id);


--
-- Name: security_presets security_presets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_presets
    ADD CONSTRAINT security_presets_pkey PRIMARY KEY (id);


--
-- Name: security_recommendations security_recommendations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_recommendations
    ADD CONSTRAINT security_recommendations_pkey PRIMARY KEY (id);


--
-- Name: security_recommendations security_recommendations_site_id_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_recommendations
    ADD CONSTRAINT security_recommendations_site_id_key_unique UNIQUE (site_id, key);


--
-- Name: security_scans security_scans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_scans
    ADD CONSTRAINT security_scans_pkey PRIMARY KEY (id);


--
-- Name: security_settings security_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_settings
    ADD CONSTRAINT security_settings_pkey PRIMARY KEY (id);


--
-- Name: security_settings security_settings_site_id_category_setting_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_settings
    ADD CONSTRAINT security_settings_site_id_category_setting_key_unique UNIQUE (site_id, category, setting_key);


--
-- Name: seo_alert_rules seo_alert_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_alert_rules
    ADD CONSTRAINT seo_alert_rules_pkey PRIMARY KEY (id);


--
-- Name: seo_audits seo_audits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_audits
    ADD CONSTRAINT seo_audits_pkey PRIMARY KEY (id);


--
-- Name: seo_content_revisions seo_content_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_content_revisions
    ADD CONSTRAINT seo_content_revisions_pkey PRIMARY KEY (id);


--
-- Name: seo_contents seo_contents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_contents
    ADD CONSTRAINT seo_contents_pkey PRIMARY KEY (id);


--
-- Name: seo_images seo_images_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_images
    ADD CONSTRAINT seo_images_pkey PRIMARY KEY (id);


--
-- Name: seo_issues seo_issues_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_issues
    ADD CONSTRAINT seo_issues_pkey PRIMARY KEY (id);


--
-- Name: seo_keyword_rankings seo_keyword_rankings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_keyword_rankings
    ADD CONSTRAINT seo_keyword_rankings_pkey PRIMARY KEY (id);


--
-- Name: seo_links seo_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_links
    ADD CONSTRAINT seo_links_pkey PRIMARY KEY (id);


--
-- Name: seo_monitors seo_monitors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_monitors
    ADD CONSTRAINT seo_monitors_pkey PRIMARY KEY (id);


--
-- Name: seo_monitors seo_monitors_site_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_monitors
    ADD CONSTRAINT seo_monitors_site_id_key UNIQUE (site_id);


--
-- Name: seo_pages seo_pages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_pages
    ADD CONSTRAINT seo_pages_pkey PRIMARY KEY (id);


--
-- Name: site_cloudflare site_cloudflare_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_cloudflare
    ADD CONSTRAINT site_cloudflare_pkey PRIMARY KEY (id);


--
-- Name: site_cloudflare site_cloudflare_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_cloudflare
    ADD CONSTRAINT site_cloudflare_site_id_unique UNIQUE (site_id);


--
-- Name: site_contents site_contents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_contents
    ADD CONSTRAINT site_contents_pkey PRIMARY KEY (id);


--
-- Name: site_contents site_contents_site_id_wp_post_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_contents
    ADD CONSTRAINT site_contents_site_id_wp_post_id_key UNIQUE (site_id, wp_post_id);


--
-- Name: site_crawls site_crawls_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_crawls
    ADD CONSTRAINT site_crawls_pkey PRIMARY KEY (id);


--
-- Name: site_health_state site_health_state_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_health_state
    ADD CONSTRAINT site_health_state_pkey PRIMARY KEY (id);


--
-- Name: site_health_state site_health_state_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_health_state
    ADD CONSTRAINT site_health_state_site_id_unique UNIQUE (site_id);


--
-- Name: site_monthly_snapshots site_monthly_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_monthly_snapshots
    ADD CONSTRAINT site_monthly_snapshots_pkey PRIMARY KEY (id);


--
-- Name: site_monthly_snapshots site_monthly_snapshots_site_id_year_month_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_monthly_snapshots
    ADD CONSTRAINT site_monthly_snapshots_site_id_year_month_unique UNIQUE (site_id, year, month);


--
-- Name: site_plugin_conflicts site_plugin_conflicts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_plugin_conflicts
    ADD CONSTRAINT site_plugin_conflicts_pkey PRIMARY KEY (id);


--
-- Name: site_plugin_conflicts site_plugin_conflicts_site_id_plugin_a_slug_plugin_b_slug_uniqu; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_plugin_conflicts
    ADD CONSTRAINT site_plugin_conflicts_site_id_plugin_a_slug_plugin_b_slug_uniqu UNIQUE (site_id, plugin_a_slug, plugin_b_slug);


--
-- Name: site_plugins site_plugins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_plugins
    ADD CONSTRAINT site_plugins_pkey PRIMARY KEY (id);


--
-- Name: site_plugins site_plugins_site_id_file_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_plugins
    ADD CONSTRAINT site_plugins_site_id_file_unique UNIQUE (site_id, file);


--
-- Name: maintenance_plan_modules site_preset_modules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_plan_modules
    ADD CONSTRAINT site_preset_modules_pkey PRIMARY KEY (id);


--
-- Name: maintenance_plans site_presets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_plans
    ADD CONSTRAINT site_presets_pkey PRIMARY KEY (id);


--
-- Name: site_report_configs site_report_configs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_report_configs
    ADD CONSTRAINT site_report_configs_pkey PRIMARY KEY (id);


--
-- Name: site_report_configs site_report_configs_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_report_configs
    ADD CONSTRAINT site_report_configs_site_id_unique UNIQUE (site_id);


--
-- Name: site_statuses site_statuses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_statuses
    ADD CONSTRAINT site_statuses_pkey PRIMARY KEY (id);


--
-- Name: site_themes site_themes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_themes
    ADD CONSTRAINT site_themes_pkey PRIMARY KEY (id);


--
-- Name: site_themes site_themes_site_id_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_themes
    ADD CONSTRAINT site_themes_site_id_slug_unique UNIQUE (site_id, slug);


--
-- Name: site_users site_users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_users
    ADD CONSTRAINT site_users_pkey PRIMARY KEY (id);


--
-- Name: site_users site_users_site_id_wp_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_users
    ADD CONSTRAINT site_users_site_id_wp_user_id_unique UNIQUE (site_id, wp_user_id);


--
-- Name: sites sites_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_pkey PRIMARY KEY (id);


--
-- Name: status_page_incident_templates status_page_incident_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_incident_templates
    ADD CONSTRAINT status_page_incident_templates_pkey PRIMARY KEY (id);


--
-- Name: status_page_incident_updates status_page_incident_updates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_incident_updates
    ADD CONSTRAINT status_page_incident_updates_pkey PRIMARY KEY (id);


--
-- Name: status_page_incidents status_page_incidents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_incidents
    ADD CONSTRAINT status_page_incidents_pkey PRIMARY KEY (id);


--
-- Name: status_page_sites status_page_sites_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_sites
    ADD CONSTRAINT status_page_sites_pkey PRIMARY KEY (id);


--
-- Name: status_page_sites status_page_sites_status_page_id_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_sites
    ADD CONSTRAINT status_page_sites_status_page_id_site_id_unique UNIQUE (status_page_id, site_id);


--
-- Name: status_pages status_pages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_pages
    ADD CONSTRAINT status_pages_pkey PRIMARY KEY (id);


--
-- Name: status_pages status_pages_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_pages
    ADD CONSTRAINT status_pages_slug_unique UNIQUE (slug);


--
-- Name: storage_destinations storage_destinations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.storage_destinations
    ADD CONSTRAINT storage_destinations_pkey PRIMARY KEY (id);


--
-- Name: theme_file_checks theme_file_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.theme_file_checks
    ADD CONSTRAINT theme_file_checks_pkey PRIMARY KEY (id);


--
-- Name: tracked_keywords tracked_keywords_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tracked_keywords
    ADD CONSTRAINT tracked_keywords_pkey PRIMARY KEY (id);


--
-- Name: tracked_keywords tracked_keywords_site_id_keyword_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tracked_keywords
    ADD CONSTRAINT tracked_keywords_site_id_keyword_unique UNIQUE (site_id, keyword);


--
-- Name: dashboard_widgets unique_user_widget; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_widgets
    ADD CONSTRAINT unique_user_widget UNIQUE (user_id, widget_type);


--
-- Name: update_logs update_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.update_logs
    ADD CONSTRAINT update_logs_pkey PRIMARY KEY (id);


--
-- Name: uptime_checks uptime_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uptime_checks
    ADD CONSTRAINT uptime_checks_pkey PRIMARY KEY (id);


--
-- Name: uptime_incidents uptime_incidents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uptime_incidents
    ADD CONSTRAINT uptime_incidents_pkey PRIMARY KEY (id);


--
-- Name: uptime_monitors uptime_monitors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uptime_monitors
    ADD CONSTRAINT uptime_monitors_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_google_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_google_id_unique UNIQUE (google_id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: vulnerability_alerts vulnerability_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vulnerability_alerts
    ADD CONSTRAINT vulnerability_alerts_pkey PRIMARY KEY (id);


--
-- Name: vulnerability_alerts vulnerability_alerts_site_id_software_slug_vulnerability_id_uni; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vulnerability_alerts
    ADD CONSTRAINT vulnerability_alerts_site_id_software_slug_vulnerability_id_uni UNIQUE (site_id, software_slug, vulnerability_id);


--
-- Name: activity_logs_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_created_at_index ON public.activity_logs USING btree (created_at);


--
-- Name: activity_logs_severity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_severity_index ON public.activity_logs USING btree (severity);


--
-- Name: activity_logs_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_site_id_created_at_index ON public.activity_logs USING btree (site_id, created_at);


--
-- Name: activity_logs_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_type_index ON public.activity_logs USING btree (type);


--
-- Name: analytics_cache_site_id_date_range_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX analytics_cache_site_id_date_range_index ON public.analytics_cache USING btree (site_id, date_range);


--
-- Name: analytics_connections_is_active_next_sync_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX analytics_connections_is_active_next_sync_at_index ON public.analytics_connections USING btree (is_active, next_sync_at);


--
-- Name: app_backups_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX app_backups_expires_at_index ON public.app_backups USING btree (expires_at);


--
-- Name: app_backups_status_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX app_backups_status_created_at_index ON public.app_backups USING btree (status, created_at);


--
-- Name: app_settings_group_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX app_settings_group_key_index ON public.app_settings USING btree ("group", key);


--
-- Name: backlinks_site_id_lost_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backlinks_site_id_lost_at_index ON public.backlinks USING btree (site_id, lost_at);


--
-- Name: backlinks_site_id_source_domain_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backlinks_site_id_source_domain_index ON public.backlinks USING btree (site_id, source_domain);


--
-- Name: backup_configs_is_enabled_next_backup_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backup_configs_is_enabled_next_backup_at_index ON public.backup_configs USING btree (is_enabled, next_backup_at);


--
-- Name: backup_configs_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backup_configs_site_id_index ON public.backup_configs USING btree (site_id);


--
-- Name: backups_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backups_expires_at_index ON public.backups USING btree (expires_at);


--
-- Name: backups_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backups_site_id_created_at_index ON public.backups USING btree (site_id, created_at);


--
-- Name: backups_site_id_restore_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backups_site_id_restore_status_index ON public.backups USING btree (site_id, restore_status);


--
-- Name: backups_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backups_site_id_status_index ON public.backups USING btree (site_id, status);


--
-- Name: backups_status_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backups_status_created_at_index ON public.backups USING btree (status, created_at);


--
-- Name: backups_storage_destination_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backups_storage_destination_id_index ON public.backups USING btree (storage_destination_id);


--
-- Name: backups_verification_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backups_verification_idx ON public.backups USING btree (verification_status, verified_at);


--
-- Name: clients_company_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX clients_company_index ON public.clients USING btree (company);


--
-- Name: clients_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX clients_status_index ON public.clients USING btree (status);


--
-- Name: cloudflare_connections_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cloudflare_connections_user_id_index ON public.cloudflare_connections USING btree (user_id);


--
-- Name: competitor_keyword_positions_competitor_site_id_keyword_date_in; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX competitor_keyword_positions_competitor_site_id_keyword_date_in ON public.competitor_keyword_positions USING btree (competitor_site_id, keyword, date);


--
-- Name: core_file_checks_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX core_file_checks_site_id_index ON public.core_file_checks USING btree (site_id);


--
-- Name: core_file_checks_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX core_file_checks_status_index ON public.core_file_checks USING btree (status);


--
-- Name: crawled_pages_site_crawl_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX crawled_pages_site_crawl_id_index ON public.crawled_pages USING btree (site_crawl_id);


--
-- Name: crawled_pages_site_crawl_id_status_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX crawled_pages_site_crawl_id_status_code_index ON public.crawled_pages USING btree (site_crawl_id, status_code);


--
-- Name: database_cleanup_configs_is_enabled_next_cleanup_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX database_cleanup_configs_is_enabled_next_cleanup_at_index ON public.database_cleanup_configs USING btree (is_enabled, next_cleanup_at);


--
-- Name: database_cleanups_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX database_cleanups_site_id_index ON public.database_cleanups USING btree (site_id);


--
-- Name: database_health_checks_site_id_checked_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX database_health_checks_site_id_checked_at_index ON public.database_health_checks USING btree (site_id, checked_at);


--
-- Name: database_health_checks_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX database_health_checks_site_id_index ON public.database_health_checks USING btree (site_id);


--
-- Name: idx_analytics_cache_fetched_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_analytics_cache_fetched_at ON public.analytics_cache USING btree (fetched_at);


--
-- Name: idx_cloudflare_cache_purges_purged_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cloudflare_cache_purges_purged_at ON public.cloudflare_cache_purges USING btree (purged_at);


--
-- Name: idx_core_file_checks_checked_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_file_checks_checked_at ON public.core_file_checks USING btree (checked_at);


--
-- Name: idx_database_cleanups_cleaned_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_database_cleanups_cleaned_at ON public.database_cleanups USING btree (cleaned_at);


--
-- Name: idx_dns_monitors_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dns_monitors_active ON public.dns_monitors USING btree (is_active, next_check_at);


--
-- Name: idx_failed_jobs_failed_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_failed_jobs_failed_at ON public.failed_jobs USING btree (failed_at);


--
-- Name: idx_keyword_positions_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_keyword_positions_date ON public.keyword_positions USING btree (date);


--
-- Name: idx_notification_logs_status_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_notification_logs_status_created ON public.notification_logs USING btree (status, created_at);


--
-- Name: idx_performance_tests_site_device_latest; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_performance_tests_site_device_latest ON public.performance_tests USING btree (site_id, device, tested_at);


--
-- Name: idx_performance_tests_site_tested_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_performance_tests_site_tested_at ON public.performance_tests USING btree (site_id, tested_at);


--
-- Name: idx_reports_schedule_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_reports_schedule_created ON public.reports USING btree (report_schedule_id, created_at);


--
-- Name: idx_reports_site_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_reports_site_status ON public.reports USING btree (site_id, status);


--
-- Name: idx_safe_updates_completed_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_safe_updates_completed_at ON public.safe_updates USING btree (completed_at);


--
-- Name: idx_search_console_cache_fetched_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_search_console_cache_fetched_at ON public.search_console_cache USING btree (fetched_at);


--
-- Name: idx_site_contents_stale; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_site_contents_stale ON public.site_contents USING btree (site_id, is_stale);


--
-- Name: idx_user_sort; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_user_sort ON public.dashboard_widgets USING btree (user_id, sort_order);


--
-- Name: in_app_notifications_user_id_read_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX in_app_notifications_user_id_read_at_index ON public.in_app_notifications USING btree (user_id, read_at);


--
-- Name: invitations_email_accepted_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invitations_email_accepted_at_index ON public.invitations USING btree (email, accepted_at);


--
-- Name: invitations_token_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invitations_token_index ON public.invitations USING btree (token);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: keyword_page_mappings_site_id_url_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX keyword_page_mappings_site_id_url_index ON public.keyword_page_mappings USING btree (site_id, url);


--
-- Name: keyword_research_results_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX keyword_research_results_user_id_created_at_index ON public.keyword_research_results USING btree (user_id, created_at);


--
-- Name: notification_channels_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_channels_is_active_index ON public.notification_channels USING btree (is_active);


--
-- Name: notification_channels_is_active_is_default_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_channels_is_active_is_default_index ON public.notification_channels USING btree (is_active, is_default);


--
-- Name: notification_channels_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_channels_type_index ON public.notification_channels USING btree (type);


--
-- Name: notification_logs_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_logs_created_at_index ON public.notification_logs USING btree (created_at);


--
-- Name: notification_logs_notification_channel_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_logs_notification_channel_id_created_at_index ON public.notification_logs USING btree (notification_channel_id, created_at);


--
-- Name: notification_logs_site_id_event_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_logs_site_id_event_index ON public.notification_logs USING btree (site_id, event);


--
-- Name: notification_templates_event_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_templates_event_index ON public.notification_templates USING btree (event);


--
-- Name: performance_monitors_is_active_next_test_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX performance_monitors_is_active_next_test_at_index ON public.performance_monitors USING btree (is_active, next_test_at);


--
-- Name: performance_monitors_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX performance_monitors_site_id_index ON public.performance_monitors USING btree (site_id);


--
-- Name: performance_tests_performance_monitor_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX performance_tests_performance_monitor_id_created_at_index ON public.performance_tests USING btree (performance_monitor_id, created_at);


--
-- Name: performance_tests_performance_monitor_id_tested_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX performance_tests_performance_monitor_id_tested_at_index ON public.performance_tests USING btree (performance_monitor_id, tested_at);


--
-- Name: performance_tests_site_id_device_tested_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX performance_tests_site_id_device_tested_at_index ON public.performance_tests USING btree (site_id, device, tested_at);


--
-- Name: personal_access_tokens_token_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_token_index ON public.personal_access_tokens USING btree (token);


--
-- Name: plugin_conflicts_plugin_a_slug_plugin_b_slug_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX plugin_conflicts_plugin_a_slug_plugin_b_slug_index ON public.plugin_conflicts USING btree (plugin_a_slug, plugin_b_slug);


--
-- Name: report_recommendations_report_id_is_included_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX report_recommendations_report_id_is_included_index ON public.report_recommendations USING btree (report_id, is_included);


--
-- Name: report_recommendations_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX report_recommendations_site_id_index ON public.report_recommendations USING btree (site_id);


--
-- Name: report_schedules_is_active_next_run_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX report_schedules_is_active_next_run_at_index ON public.report_schedules USING btree (is_active, next_run_at);


--
-- Name: report_schedules_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX report_schedules_site_id_index ON public.report_schedules USING btree (site_id);


--
-- Name: reports_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX reports_site_id_created_at_index ON public.reports USING btree (site_id, created_at);


--
-- Name: reports_view_token_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX reports_view_token_unique ON public.reports USING btree (view_token);


--
-- Name: rollback_points_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX rollback_points_site_id_status_index ON public.rollback_points USING btree (site_id, status);


--
-- Name: safe_updates_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX safe_updates_site_id_status_index ON public.safe_updates USING btree (site_id, status);


--
-- Name: search_console_cache_site_id_date_range_data_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX search_console_cache_site_id_date_range_data_type_index ON public.search_console_cache USING btree (site_id, date_range, data_type);


--
-- Name: search_console_connections_is_active_next_sync_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX search_console_connections_is_active_next_sync_at_index ON public.search_console_connections USING btree (is_active, next_sync_at);


--
-- Name: security_activity_logs_event_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_activity_logs_event_type_index ON public.security_activity_logs USING btree (event_type);


--
-- Name: security_activity_logs_ip_address_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_activity_logs_ip_address_index ON public.security_activity_logs USING btree (ip_address);


--
-- Name: security_activity_logs_site_id_event_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_activity_logs_site_id_event_category_index ON public.security_activity_logs USING btree (site_id, event_category);


--
-- Name: security_activity_logs_site_id_event_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_activity_logs_site_id_event_type_index ON public.security_activity_logs USING btree (site_id, event_type);


--
-- Name: security_activity_logs_site_id_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_activity_logs_site_id_occurred_at_index ON public.security_activity_logs USING btree (site_id, occurred_at);


--
-- Name: security_banned_ips_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_banned_ips_expires_at_index ON public.security_banned_ips USING btree (expires_at);


--
-- Name: security_banned_ips_site_id_ip_address_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_banned_ips_site_id_ip_address_index ON public.security_banned_ips USING btree (site_id, ip_address);


--
-- Name: security_commands_site_id_category_action_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_commands_site_id_category_action_index ON public.security_commands USING btree (site_id, category, action);


--
-- Name: security_commands_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_commands_site_id_status_index ON public.security_commands USING btree (site_id, status);


--
-- Name: security_commands_status_picked_up_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_commands_status_picked_up_index ON public.security_commands USING btree (status, picked_up_at);


--
-- Name: security_ip_lists_site_id_list_type_ip_address_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_ip_lists_site_id_list_type_ip_address_index ON public.security_ip_lists USING btree (site_id, list_type, ip_address);


--
-- Name: security_issues_site_id_severity_is_fixed_is_ignored_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_issues_site_id_severity_is_fixed_is_ignored_index ON public.security_issues USING btree (site_id, severity, is_fixed, is_ignored);


--
-- Name: security_monitors_is_active_next_scan_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_monitors_is_active_next_scan_at_index ON public.security_monitors USING btree (is_active, next_scan_at);


--
-- Name: security_recommendations_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_recommendations_site_id_status_index ON public.security_recommendations USING btree (site_id, status);


--
-- Name: security_scans_site_id_scanned_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_scans_site_id_scanned_at_index ON public.security_scans USING btree (site_id, scanned_at);


--
-- Name: security_settings_site_id_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX security_settings_site_id_category_index ON public.security_settings USING btree (site_id, category);


--
-- Name: seo_alert_rules_site_id_rule_type_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_alert_rules_site_id_rule_type_is_active_index ON public.seo_alert_rules USING btree (site_id, rule_type, is_active);


--
-- Name: seo_audits_site_id_scanned_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_audits_site_id_scanned_at_index ON public.seo_audits USING btree (site_id, scanned_at);


--
-- Name: seo_content_revisions_seo_content_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_content_revisions_seo_content_id_index ON public.seo_content_revisions USING btree (seo_content_id);


--
-- Name: seo_contents_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_contents_site_id_status_index ON public.seo_contents USING btree (site_id, status);


--
-- Name: seo_contents_status_scheduled_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_contents_status_scheduled_at_index ON public.seo_contents USING btree (status, scheduled_at);


--
-- Name: seo_contents_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_contents_user_id_index ON public.seo_contents USING btree (user_id);


--
-- Name: seo_images_seo_audit_id_image_url_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_images_seo_audit_id_image_url_hash_index ON public.seo_images USING btree (seo_audit_id, image_url_hash);


--
-- Name: seo_images_seo_audit_id_is_broken_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_images_seo_audit_id_is_broken_index ON public.seo_images USING btree (seo_audit_id, is_broken);


--
-- Name: seo_issues_seo_audit_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_issues_seo_audit_id_index ON public.seo_issues USING btree (seo_audit_id);


--
-- Name: seo_issues_site_id_severity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_issues_site_id_severity_index ON public.seo_issues USING btree (site_id, severity);


--
-- Name: seo_keyword_rankings_keyword_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_keyword_rankings_keyword_hash_index ON public.seo_keyword_rankings USING btree (keyword_hash);


--
-- Name: seo_keyword_rankings_site_id_is_tracked_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_keyword_rankings_site_id_is_tracked_index ON public.seo_keyword_rankings USING btree (site_id, is_tracked);


--
-- Name: seo_keyword_rankings_site_id_keyword_hash_recorded_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_keyword_rankings_site_id_keyword_hash_recorded_date_index ON public.seo_keyword_rankings USING btree (site_id, keyword_hash, recorded_date);


--
-- Name: seo_links_audit_target_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_links_audit_target_index ON public.seo_links USING btree (seo_audit_id, target_url_hash);


--
-- Name: seo_links_audit_type_broken_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_links_audit_type_broken_index ON public.seo_links USING btree (seo_audit_id, type, is_broken);


--
-- Name: seo_monitors_is_active_next_audit_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_monitors_is_active_next_audit_at_index ON public.seo_monitors USING btree (is_active, next_audit_at);


--
-- Name: seo_pages_audit_indexable_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_pages_audit_indexable_index ON public.seo_pages USING btree (seo_audit_id, is_indexable);


--
-- Name: seo_pages_audit_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_pages_audit_status_index ON public.seo_pages USING btree (seo_audit_id, status_code);


--
-- Name: seo_pages_site_url_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_pages_site_url_index ON public.seo_pages USING btree (site_id, url_hash);


--
-- Name: seo_pages_url_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_pages_url_hash_index ON public.seo_pages USING btree (url_hash);


--
-- Name: site_cloudflare_is_active_next_sync_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_cloudflare_is_active_next_sync_at_index ON public.site_cloudflare USING btree (is_active, next_sync_at);


--
-- Name: site_crawls_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_crawls_site_id_created_at_index ON public.site_crawls USING btree (site_id, created_at);


--
-- Name: site_crawls_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_crawls_status_index ON public.site_crawls USING btree (status);


--
-- Name: site_health_state_circuit_state_is_monitoring_disabled_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_health_state_circuit_state_is_monitoring_disabled_index ON public.site_health_state USING btree (circuit_state, is_monitoring_disabled);


--
-- Name: site_plugin_conflicts_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_plugin_conflicts_site_id_index ON public.site_plugin_conflicts USING btree (site_id);


--
-- Name: site_plugins_site_id_has_update_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_plugins_site_id_has_update_index ON public.site_plugins USING btree (site_id, has_update);


--
-- Name: site_plugins_site_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_plugins_site_id_is_active_index ON public.site_plugins USING btree (site_id, is_active);


--
-- Name: site_themes_site_id_has_update_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_themes_site_id_has_update_index ON public.site_themes USING btree (site_id, has_update);


--
-- Name: site_themes_site_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_themes_site_id_is_active_index ON public.site_themes USING btree (site_id, is_active);


--
-- Name: sites_client_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sites_client_id_index ON public.sites USING btree (client_id);


--
-- Name: sites_health_score_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sites_health_score_index ON public.sites USING btree (health_score);


--
-- Name: sites_health_score_is_up_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sites_health_score_is_up_index ON public.sites USING btree (health_score, is_up);


--
-- Name: sites_is_prospect_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sites_is_prospect_index ON public.sites USING btree (is_prospect);


--
-- Name: sites_is_up_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sites_is_up_index ON public.sites USING btree (is_up);


--
-- Name: sites_sort_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sites_sort_order_index ON public.sites USING btree (sort_order);


--
-- Name: sites_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sites_status_index ON public.sites USING btree (status);


--
-- Name: sites_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sites_user_id_index ON public.sites USING btree (user_id);


--
-- Name: status_page_incidents_status_page_id_status_started_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX status_page_incidents_status_page_id_status_started_at_index ON public.status_page_incidents USING btree (status_page_id, status, started_at);


--
-- Name: theme_file_checks_site_slug; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX theme_file_checks_site_slug ON public.theme_file_checks USING btree (site_id, theme_slug);


--
-- Name: theme_file_checks_site_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX theme_file_checks_site_status ON public.theme_file_checks USING btree (site_id, status);


--
-- Name: update_logs_site_id_performed_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX update_logs_site_id_performed_at_index ON public.update_logs USING btree (site_id, performed_at);


--
-- Name: update_logs_site_id_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX update_logs_site_id_type_index ON public.update_logs USING btree (site_id, type);


--
-- Name: uptime_checks_monitor_id_checked_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uptime_checks_monitor_id_checked_at_index ON public.uptime_checks USING btree (monitor_id, checked_at);


--
-- Name: uptime_checks_monitor_id_checked_at_is_up_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uptime_checks_monitor_id_checked_at_is_up_index ON public.uptime_checks USING btree (monitor_id, checked_at, is_up);


--
-- Name: uptime_checks_monitor_id_is_up_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uptime_checks_monitor_id_is_up_index ON public.uptime_checks USING btree (monitor_id, is_up);


--
-- Name: uptime_incidents_monitor_id_started_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uptime_incidents_monitor_id_started_at_index ON public.uptime_incidents USING btree (monitor_id, started_at);


--
-- Name: uptime_incidents_monitor_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uptime_incidents_monitor_id_status_index ON public.uptime_incidents USING btree (monitor_id, status);


--
-- Name: uptime_monitors_current_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uptime_monitors_current_state_index ON public.uptime_monitors USING btree (current_state);


--
-- Name: uptime_monitors_last_checked_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uptime_monitors_last_checked_at_index ON public.uptime_monitors USING btree (last_checked_at);


--
-- Name: uptime_monitors_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uptime_monitors_site_id_index ON public.uptime_monitors USING btree (site_id);


--
-- Name: uptime_monitors_status_current_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX uptime_monitors_status_current_state_index ON public.uptime_monitors USING btree (status, current_state);


--
-- Name: vulnerability_alerts_severity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vulnerability_alerts_severity_index ON public.vulnerability_alerts USING btree (severity);


--
-- Name: vulnerability_alerts_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vulnerability_alerts_site_id_status_index ON public.vulnerability_alerts USING btree (site_id, status);


--
-- Name: vulnerability_alerts_site_id_status_severity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vulnerability_alerts_site_id_status_severity_index ON public.vulnerability_alerts USING btree (site_id, status, severity);


--
-- Name: activity_logs activity_logs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_logs
    ADD CONSTRAINT activity_logs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: activity_logs activity_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_logs
    ADD CONSTRAINT activity_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: analytics_cache analytics_cache_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_cache
    ADD CONSTRAINT analytics_cache_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: analytics_connections analytics_connections_google_connection_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_connections
    ADD CONSTRAINT analytics_connections_google_connection_id_foreign FOREIGN KEY (google_connection_id) REFERENCES public.google_connections(id) ON DELETE CASCADE;


--
-- Name: analytics_connections analytics_connections_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_connections
    ADD CONSTRAINT analytics_connections_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: app_backup_configs app_backup_configs_storage_destination_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_backup_configs
    ADD CONSTRAINT app_backup_configs_storage_destination_id_foreign FOREIGN KEY (storage_destination_id) REFERENCES public.storage_destinations(id) ON DELETE SET NULL;


--
-- Name: app_backups app_backups_storage_destination_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_backups
    ADD CONSTRAINT app_backups_storage_destination_id_foreign FOREIGN KEY (storage_destination_id) REFERENCES public.storage_destinations(id) ON DELETE SET NULL;


--
-- Name: backlink_snapshots backlink_snapshots_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backlink_snapshots
    ADD CONSTRAINT backlink_snapshots_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: backlinks backlinks_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backlinks
    ADD CONSTRAINT backlinks_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: backup_configs backup_configs_secondary_storage_destination_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_configs
    ADD CONSTRAINT backup_configs_secondary_storage_destination_id_foreign FOREIGN KEY (secondary_storage_destination_id) REFERENCES public.storage_destinations(id) ON DELETE SET NULL;


--
-- Name: backup_configs backup_configs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_configs
    ADD CONSTRAINT backup_configs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: backup_configs backup_configs_storage_destination_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_configs
    ADD CONSTRAINT backup_configs_storage_destination_id_foreign FOREIGN KEY (storage_destination_id) REFERENCES public.storage_destinations(id) ON DELETE SET NULL;


--
-- Name: backups backups_parent_backup_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backups
    ADD CONSTRAINT backups_parent_backup_id_foreign FOREIGN KEY (parent_backup_id) REFERENCES public.backups(id) ON DELETE SET NULL;


--
-- Name: backups backups_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backups
    ADD CONSTRAINT backups_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: backups backups_storage_destination_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backups
    ADD CONSTRAINT backups_storage_destination_id_foreign FOREIGN KEY (storage_destination_id) REFERENCES public.storage_destinations(id) ON DELETE SET NULL;


--
-- Name: client_costs client_costs_client_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_costs
    ADD CONSTRAINT client_costs_client_id_fkey FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: client_costs client_costs_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_costs
    ADD CONSTRAINT client_costs_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: client_revenues client_revenues_client_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_revenues
    ADD CONSTRAINT client_revenues_client_id_fkey FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: client_user client_user_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_user
    ADD CONSTRAINT client_user_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: client_user client_user_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.client_user
    ADD CONSTRAINT client_user_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: cloudflare_cache_purges cloudflare_cache_purges_purged_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloudflare_cache_purges
    ADD CONSTRAINT cloudflare_cache_purges_purged_by_foreign FOREIGN KEY (purged_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cloudflare_cache_purges cloudflare_cache_purges_site_cloudflare_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloudflare_cache_purges
    ADD CONSTRAINT cloudflare_cache_purges_site_cloudflare_id_foreign FOREIGN KEY (site_cloudflare_id) REFERENCES public.site_cloudflare(id) ON DELETE CASCADE;


--
-- Name: cloudflare_connections cloudflare_connections_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloudflare_connections
    ADD CONSTRAINT cloudflare_connections_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: competitor_keyword_positions competitor_keyword_positions_competitor_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.competitor_keyword_positions
    ADD CONSTRAINT competitor_keyword_positions_competitor_site_id_foreign FOREIGN KEY (competitor_site_id) REFERENCES public.competitor_sites(id) ON DELETE CASCADE;


--
-- Name: competitor_sites competitor_sites_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.competitor_sites
    ADD CONSTRAINT competitor_sites_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: core_file_checks core_file_checks_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_file_checks
    ADD CONSTRAINT core_file_checks_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: crawled_pages crawled_pages_site_crawl_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.crawled_pages
    ADD CONSTRAINT crawled_pages_site_crawl_id_fkey FOREIGN KEY (site_crawl_id) REFERENCES public.site_crawls(id) ON DELETE CASCADE;


--
-- Name: dashboard_widgets dashboard_widgets_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_widgets
    ADD CONSTRAINT dashboard_widgets_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: database_cleanup_configs database_cleanup_configs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.database_cleanup_configs
    ADD CONSTRAINT database_cleanup_configs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: database_cleanups database_cleanups_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.database_cleanups
    ADD CONSTRAINT database_cleanups_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: database_health_checks database_health_checks_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.database_health_checks
    ADD CONSTRAINT database_health_checks_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: dns_changes dns_changes_dns_monitor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_changes
    ADD CONSTRAINT dns_changes_dns_monitor_id_fkey FOREIGN KEY (dns_monitor_id) REFERENCES public.dns_monitors(id) ON DELETE CASCADE;


--
-- Name: dns_monitors dns_monitors_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_monitors
    ADD CONSTRAINT dns_monitors_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: health_score_history health_score_history_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.health_score_history
    ADD CONSTRAINT health_score_history_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: in_app_notifications in_app_notifications_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.in_app_notifications
    ADD CONSTRAINT in_app_notifications_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: invitations invitations_invited_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invitations
    ADD CONSTRAINT invitations_invited_by_foreign FOREIGN KEY (invited_by) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: keyword_page_mappings keyword_page_mappings_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_page_mappings
    ADD CONSTRAINT keyword_page_mappings_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: keyword_page_mappings keyword_page_mappings_tracked_keyword_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_page_mappings
    ADD CONSTRAINT keyword_page_mappings_tracked_keyword_id_foreign FOREIGN KEY (tracked_keyword_id) REFERENCES public.tracked_keywords(id) ON DELETE CASCADE;


--
-- Name: keyword_positions keyword_positions_tracked_keyword_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_positions
    ADD CONSTRAINT keyword_positions_tracked_keyword_id_foreign FOREIGN KEY (tracked_keyword_id) REFERENCES public.tracked_keywords(id) ON DELETE CASCADE;


--
-- Name: keyword_research_results keyword_research_results_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_research_results
    ADD CONSTRAINT keyword_research_results_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: keyword_research_results keyword_research_results_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_research_results
    ADD CONSTRAINT keyword_research_results_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: maintenance_plan_modules maintenance_plan_modules_maintenance_plan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_plan_modules
    ADD CONSTRAINT maintenance_plan_modules_maintenance_plan_id_foreign FOREIGN KEY (maintenance_plan_id) REFERENCES public.maintenance_plans(id) ON DELETE CASCADE;


--
-- Name: notification_escalation_rules notification_escalation_rules_escalation_channel_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_escalation_rules
    ADD CONSTRAINT notification_escalation_rules_escalation_channel_id_foreign FOREIGN KEY (escalation_channel_id) REFERENCES public.notification_channels(id) ON DELETE CASCADE;


--
-- Name: notification_escalation_rules notification_escalation_rules_source_channel_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_escalation_rules
    ADD CONSTRAINT notification_escalation_rules_source_channel_id_foreign FOREIGN KEY (source_channel_id) REFERENCES public.notification_channels(id) ON DELETE CASCADE;


--
-- Name: notification_event_preferences notification_event_preferences_notification_channel_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_event_preferences
    ADD CONSTRAINT notification_event_preferences_notification_channel_id_fkey FOREIGN KEY (notification_channel_id) REFERENCES public.notification_channels(id) ON DELETE CASCADE;


--
-- Name: notification_event_preferences notification_event_preferences_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_event_preferences
    ADD CONSTRAINT notification_event_preferences_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: notification_logs notification_logs_notification_channel_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_logs
    ADD CONSTRAINT notification_logs_notification_channel_id_foreign FOREIGN KEY (notification_channel_id) REFERENCES public.notification_channels(id) ON DELETE CASCADE;


--
-- Name: notification_logs notification_logs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_logs
    ADD CONSTRAINT notification_logs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: performance_monitors performance_monitors_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.performance_monitors
    ADD CONSTRAINT performance_monitors_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: performance_pages performance_pages_performance_monitor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.performance_pages
    ADD CONSTRAINT performance_pages_performance_monitor_id_foreign FOREIGN KEY (performance_monitor_id) REFERENCES public.performance_monitors(id) ON DELETE CASCADE;


--
-- Name: performance_tests performance_tests_performance_monitor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.performance_tests
    ADD CONSTRAINT performance_tests_performance_monitor_id_foreign FOREIGN KEY (performance_monitor_id) REFERENCES public.performance_monitors(id) ON DELETE CASCADE;


--
-- Name: performance_tests performance_tests_performance_page_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.performance_tests
    ADD CONSTRAINT performance_tests_performance_page_id_foreign FOREIGN KEY (performance_page_id) REFERENCES public.performance_pages(id) ON DELETE SET NULL;


--
-- Name: performance_tests performance_tests_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.performance_tests
    ADD CONSTRAINT performance_tests_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: personal_access_tokens personal_access_tokens_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: php_error_logs php_error_logs_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.php_error_logs
    ADD CONSTRAINT php_error_logs_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: recommendation_templates recommendation_templates_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recommendation_templates
    ADD CONSTRAINT recommendation_templates_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: report_recommendations report_recommendations_report_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_recommendations
    ADD CONSTRAINT report_recommendations_report_id_foreign FOREIGN KEY (report_id) REFERENCES public.reports(id) ON DELETE CASCADE;


--
-- Name: report_recommendations report_recommendations_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_recommendations
    ADD CONSTRAINT report_recommendations_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: report_schedules report_schedules_report_template_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_schedules
    ADD CONSTRAINT report_schedules_report_template_id_foreign FOREIGN KEY (report_template_id) REFERENCES public.report_templates(id) ON DELETE CASCADE;


--
-- Name: report_schedules report_schedules_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_schedules
    ADD CONSTRAINT report_schedules_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: reports reports_report_schedule_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reports
    ADD CONSTRAINT reports_report_schedule_id_foreign FOREIGN KEY (report_schedule_id) REFERENCES public.report_schedules(id) ON DELETE SET NULL;


--
-- Name: reports reports_report_template_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reports
    ADD CONSTRAINT reports_report_template_id_foreign FOREIGN KEY (report_template_id) REFERENCES public.report_templates(id) ON DELETE SET NULL;


--
-- Name: reports reports_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reports
    ADD CONSTRAINT reports_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: rollback_points rollback_points_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rollback_points
    ADD CONSTRAINT rollback_points_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: safe_updates safe_updates_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.safe_updates
    ADD CONSTRAINT safe_updates_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: search_console_cache search_console_cache_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.search_console_cache
    ADD CONSTRAINT search_console_cache_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: search_console_connections search_console_connections_google_connection_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.search_console_connections
    ADD CONSTRAINT search_console_connections_google_connection_id_foreign FOREIGN KEY (google_connection_id) REFERENCES public.google_connections(id) ON DELETE CASCADE;


--
-- Name: search_console_connections search_console_connections_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.search_console_connections
    ADD CONSTRAINT search_console_connections_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: security_activity_logs security_activity_logs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_activity_logs
    ADD CONSTRAINT security_activity_logs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: security_banned_ips security_banned_ips_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_banned_ips
    ADD CONSTRAINT security_banned_ips_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: security_commands security_commands_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_commands
    ADD CONSTRAINT security_commands_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: security_ip_lists security_ip_lists_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_ip_lists
    ADD CONSTRAINT security_ip_lists_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: security_issues security_issues_security_scan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_issues
    ADD CONSTRAINT security_issues_security_scan_id_foreign FOREIGN KEY (security_scan_id) REFERENCES public.security_scans(id) ON DELETE CASCADE;


--
-- Name: security_issues security_issues_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_issues
    ADD CONSTRAINT security_issues_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: security_monitors security_monitors_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_monitors
    ADD CONSTRAINT security_monitors_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: security_preset_site security_preset_site_security_preset_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_preset_site
    ADD CONSTRAINT security_preset_site_security_preset_id_foreign FOREIGN KEY (security_preset_id) REFERENCES public.security_presets(id) ON DELETE CASCADE;


--
-- Name: security_preset_site security_preset_site_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_preset_site
    ADD CONSTRAINT security_preset_site_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: security_presets security_presets_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_presets
    ADD CONSTRAINT security_presets_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: security_recommendations security_recommendations_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_recommendations
    ADD CONSTRAINT security_recommendations_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: security_scans security_scans_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_scans
    ADD CONSTRAINT security_scans_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: security_settings security_settings_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_settings
    ADD CONSTRAINT security_settings_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: seo_alert_rules seo_alert_rules_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_alert_rules
    ADD CONSTRAINT seo_alert_rules_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: seo_audits seo_audits_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_audits
    ADD CONSTRAINT seo_audits_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: seo_content_revisions seo_content_revisions_seo_content_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_content_revisions
    ADD CONSTRAINT seo_content_revisions_seo_content_id_fkey FOREIGN KEY (seo_content_id) REFERENCES public.seo_contents(id) ON DELETE CASCADE;


--
-- Name: seo_contents seo_contents_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_contents
    ADD CONSTRAINT seo_contents_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: seo_contents seo_contents_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_contents
    ADD CONSTRAINT seo_contents_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: seo_images seo_images_seo_audit_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_images
    ADD CONSTRAINT seo_images_seo_audit_id_foreign FOREIGN KEY (seo_audit_id) REFERENCES public.seo_audits(id) ON DELETE CASCADE;


--
-- Name: seo_images seo_images_seo_page_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_images
    ADD CONSTRAINT seo_images_seo_page_id_foreign FOREIGN KEY (seo_page_id) REFERENCES public.seo_pages(id) ON DELETE CASCADE;


--
-- Name: seo_issues seo_issues_seo_audit_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_issues
    ADD CONSTRAINT seo_issues_seo_audit_id_fkey FOREIGN KEY (seo_audit_id) REFERENCES public.seo_audits(id) ON DELETE CASCADE;


--
-- Name: seo_issues seo_issues_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_issues
    ADD CONSTRAINT seo_issues_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: seo_keyword_rankings seo_keyword_rankings_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_keyword_rankings
    ADD CONSTRAINT seo_keyword_rankings_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: seo_links seo_links_seo_audit_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_links
    ADD CONSTRAINT seo_links_seo_audit_id_fkey FOREIGN KEY (seo_audit_id) REFERENCES public.seo_audits(id) ON DELETE CASCADE;


--
-- Name: seo_links seo_links_seo_page_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_links
    ADD CONSTRAINT seo_links_seo_page_id_fkey FOREIGN KEY (seo_page_id) REFERENCES public.seo_pages(id) ON DELETE CASCADE;


--
-- Name: seo_monitors seo_monitors_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_monitors
    ADD CONSTRAINT seo_monitors_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: seo_pages seo_pages_seo_audit_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_pages
    ADD CONSTRAINT seo_pages_seo_audit_id_fkey FOREIGN KEY (seo_audit_id) REFERENCES public.seo_audits(id) ON DELETE CASCADE;


--
-- Name: seo_pages seo_pages_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_pages
    ADD CONSTRAINT seo_pages_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_cloudflare site_cloudflare_cloudflare_connection_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_cloudflare
    ADD CONSTRAINT site_cloudflare_cloudflare_connection_id_foreign FOREIGN KEY (cloudflare_connection_id) REFERENCES public.cloudflare_connections(id) ON DELETE CASCADE;


--
-- Name: site_cloudflare site_cloudflare_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_cloudflare
    ADD CONSTRAINT site_cloudflare_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_contents site_contents_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_contents
    ADD CONSTRAINT site_contents_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_crawls site_crawls_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_crawls
    ADD CONSTRAINT site_crawls_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_crawls site_crawls_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_crawls
    ADD CONSTRAINT site_crawls_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: site_health_state site_health_state_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_health_state
    ADD CONSTRAINT site_health_state_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_monthly_snapshots site_monthly_snapshots_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_monthly_snapshots
    ADD CONSTRAINT site_monthly_snapshots_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_plugin_conflicts site_plugin_conflicts_plugin_conflict_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_plugin_conflicts
    ADD CONSTRAINT site_plugin_conflicts_plugin_conflict_id_foreign FOREIGN KEY (plugin_conflict_id) REFERENCES public.plugin_conflicts(id) ON DELETE SET NULL;


--
-- Name: site_plugin_conflicts site_plugin_conflicts_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_plugin_conflicts
    ADD CONSTRAINT site_plugin_conflicts_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_plugins site_plugins_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_plugins
    ADD CONSTRAINT site_plugins_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: maintenance_plans site_presets_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_plans
    ADD CONSTRAINT site_presets_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: maintenance_plans site_presets_source_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_plans
    ADD CONSTRAINT site_presets_source_site_id_foreign FOREIGN KEY (source_site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: site_report_configs site_report_configs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_report_configs
    ADD CONSTRAINT site_report_configs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_themes site_themes_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_themes
    ADD CONSTRAINT site_themes_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_users site_users_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_users
    ADD CONSTRAINT site_users_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: sites sites_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: sites sites_maintenance_plan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_maintenance_plan_id_foreign FOREIGN KEY (maintenance_plan_id) REFERENCES public.maintenance_plans(id) ON DELETE SET NULL;


--
-- Name: sites sites_report_template_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_report_template_id_foreign FOREIGN KEY (report_template_id) REFERENCES public.report_templates(id) ON DELETE SET NULL;


--
-- Name: sites sites_site_status_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_site_status_id_foreign FOREIGN KEY (site_status_id) REFERENCES public.site_statuses(id) ON DELETE SET NULL;


--
-- Name: sites sites_wp_admin_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_wp_admin_user_id_foreign FOREIGN KEY (wp_admin_user_id) REFERENCES public.site_users(id) ON DELETE SET NULL;


--
-- Name: status_page_incident_updates status_page_incident_updates_status_page_incident_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_incident_updates
    ADD CONSTRAINT status_page_incident_updates_status_page_incident_id_foreign FOREIGN KEY (status_page_incident_id) REFERENCES public.status_page_incidents(id) ON DELETE CASCADE;


--
-- Name: status_page_incidents status_page_incidents_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_incidents
    ADD CONSTRAINT status_page_incidents_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: status_page_incidents status_page_incidents_status_page_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_incidents
    ADD CONSTRAINT status_page_incidents_status_page_id_foreign FOREIGN KEY (status_page_id) REFERENCES public.status_pages(id) ON DELETE CASCADE;


--
-- Name: status_page_sites status_page_sites_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_sites
    ADD CONSTRAINT status_page_sites_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: status_page_sites status_page_sites_status_page_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_sites
    ADD CONSTRAINT status_page_sites_status_page_id_foreign FOREIGN KEY (status_page_id) REFERENCES public.status_pages(id) ON DELETE CASCADE;


--
-- Name: status_pages status_pages_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_pages
    ADD CONSTRAINT status_pages_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE SET NULL;


--
-- Name: status_pages status_pages_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_pages
    ADD CONSTRAINT status_pages_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: theme_file_checks theme_file_checks_site_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.theme_file_checks
    ADD CONSTRAINT theme_file_checks_site_id_fkey FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: tracked_keywords tracked_keywords_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tracked_keywords
    ADD CONSTRAINT tracked_keywords_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: update_logs update_logs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.update_logs
    ADD CONSTRAINT update_logs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: update_logs update_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.update_logs
    ADD CONSTRAINT update_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: uptime_checks uptime_checks_monitor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uptime_checks
    ADD CONSTRAINT uptime_checks_monitor_id_foreign FOREIGN KEY (monitor_id) REFERENCES public.uptime_monitors(id) ON DELETE CASCADE;


--
-- Name: uptime_incidents uptime_incidents_monitor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uptime_incidents
    ADD CONSTRAINT uptime_incidents_monitor_id_foreign FOREIGN KEY (monitor_id) REFERENCES public.uptime_monitors(id) ON DELETE CASCADE;


--
-- Name: uptime_monitors uptime_monitors_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.uptime_monitors
    ADD CONSTRAINT uptime_monitors_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: vulnerability_alerts vulnerability_alerts_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vulnerability_alerts
    ADD CONSTRAINT vulnerability_alerts_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict LakcBIjEOjpsFQ6JIhdL26LBO22IrLos756EWXPJQEd8Y50Lkz9ZidjXJq5HIqD

--
-- Migration batch insert
--
INSERT INTO "migrations" ("migration", "batch") VALUES
    ('0001_01_01_000000_create_users_table', 1),
    ('0001_01_01_000001_create_cache_table', 1),
    ('0001_01_01_000002_create_jobs_table', 1),
    ('2026_02_02_063259_create_clients_table', 1),
    ('2026_02_02_063300_create_sites_table', 1),
    ('2026_02_02_070001_create_uptime_monitors_table', 1),
    ('2026_02_02_070002_create_uptime_checks_table', 1),
    ('2026_02_02_070003_create_uptime_incidents_table', 1),
    ('2026_02_02_070004_create_app_settings_table', 1),
    ('2026_02_02_070005_create_notification_channels_table', 1),
    ('2026_02_02_070006_add_profile_fields_to_users_table', 1),
    ('2026_02_02_080001_create_ssl_certificates_table', 1),
    ('2026_02_02_080002_create_ssl_check_history_table', 1),
    ('2026_02_02_080003_create_domain_monitors_table', 1),
    ('2026_02_02_080004_create_domain_check_history_table', 1),
    ('2026_02_02_100001_add_wordpress_fields_to_sites_table', 1),
    ('2026_02_02_100002_create_site_plugins_table', 1),
    ('2026_02_02_100003_create_site_themes_table', 1),
    ('2026_02_02_100004_create_update_logs_table', 1),
    ('2026_02_02_110001_create_storage_destinations_table', 1),
    ('2026_02_02_110002_create_backup_configs_table', 1),
    ('2026_02_02_110003_create_backups_table', 1),
    ('2026_02_02_120001_create_site_users_table', 1),
    ('2026_02_02_132332_add_progress_columns_to_backups_table', 1),
    ('2026_02_02_140652_add_restore_progress_columns_to_backups_table', 1),
    ('2026_02_02_150001_create_performance_monitors_table', 1),
    ('2026_02_02_150002_create_performance_tests_table', 1),
    ('2026_02_03_000001_enhance_performance_module', 1),
    ('2026_02_03_060001_create_link_monitors_table', 1),
    ('2026_02_03_060002_create_link_scans_table', 1),
    ('2026_02_03_060003_create_links_table', 1),
    ('2026_02_03_100001_create_google_connections_table', 1),
    ('2026_02_03_100002_create_analytics_connections_table', 1),
    ('2026_02_03_100003_create_search_console_connections_table', 1),
    ('2026_02_03_100004_create_analytics_cache_table', 1),
    ('2026_02_03_100005_create_search_console_cache_table', 1),
    ('2026_02_03_200001_create_report_templates_table', 1),
    ('2026_02_03_200002_create_report_schedules_table', 1),
    ('2026_02_03_200003_create_reports_table', 1),
    ('2026_02_03_300001_create_activity_logs_table', 1),
    ('2026_02_04_000001_create_site_statuses_table', 1),
    ('2026_02_04_000002_add_site_status_id_to_sites_table', 1),
    ('2026_02_04_100001_add_fields_to_notification_channels_table', 1),
    ('2026_02_04_100002_create_notification_logs_table', 1),
    ('2026_02_04_200001_create_maintenance_windows_table', 1),
    ('2026_02_04_300001_create_dns_records_cache_table', 1),
    ('2026_02_05_100001_create_core_file_checks_table', 1),
    ('2026_02_05_200001_add_abandonment_columns_to_site_plugins_table', 1),
    ('2026_02_05_300001_create_plugin_conflicts_table', 1),
    ('2026_02_05_300002_create_site_plugin_conflicts_table', 1),
    ('2026_02_05_400001_create_site_cron_jobs_table', 1),
    ('2026_02_05_500001_create_database_cleanups_table', 1),
    ('2026_02_05_600001_add_sort_order_to_sites_table', 1),
    ('2026_02_05_600001_create_error_logs_table', 1),
    ('2026_02_05_700001_create_database_health_checks_table', 1),
    ('2026_02_05_800001_create_email_health_checks_table', 1),
    ('2026_02_06_063408_add_fields_to_clients_table', 1),
    ('2026_02_06_080003_add_performance_indexes', 1),
    ('2026_02_06_100001_create_security_scans_table', 1),
    ('2026_02_06_100002_create_security_issues_table', 1),
    ('2026_02_06_200001_create_security_recommendations_table', 1),
    ('2026_02_06_300001_create_vulnerability_alerts_table', 1),
    ('2026_02_06_400001_create_wp_audit_logs_table', 1),
    ('2026_02_06_500001_create_ip_rules_table', 1),
    ('2026_02_06_500002_create_blocked_requests_table', 1),
    ('2026_02_06_600001_create_cloudflare_connections_table', 1),
    ('2026_02_06_600002_create_site_cloudflare_table', 1),
    ('2026_02_06_600003_create_cloudflare_cache_purges_table', 1),
    ('2026_02_06_700001_create_status_pages_table', 1),
    ('2026_02_06_700002_create_status_page_sites_table', 1),
    ('2026_02_06_700003_create_status_page_incidents_table', 1),
    ('2026_02_06_700004_create_status_page_incident_updates_table', 1),
    ('2026_02_06_700005_add_update_status_page_to_maintenance_windows_table', 1),
    ('2026_02_07_080207_make_sites_client_id_nullable', 1),
    ('2026_02_07_100001_create_app_backup_configs_table', 1),
    ('2026_02_07_100001_create_rollback_points_table', 1),
    ('2026_02_07_100002_create_app_backups_table', 1),
    ('2026_02_07_100002_create_safe_updates_table', 1),
    ('2026_02_07_200001_create_resource_checks_table', 1),
    ('2026_02_07_400001_create_woocommerce_stats_table', 1),
    ('2026_02_07_400002_create_woocommerce_alerts_table', 1),
    ('2026_02_07_400003_add_has_woocommerce_to_sites_table', 1),
    ('2026_02_08_100001_add_production_performance_indexes', 1),
    ('2026_02_08_200001_add_favicon_and_screenshot_to_sites', 1),
    ('2026_02_09_100001_create_keyword_tracking_tables', 1),
    ('2026_02_10_091735_create_dashboard_widgets_table', 1),
    ('2026_02_10_094856_create_site_widgets_table', 1),
    ('2026_02_10_102249_drop_site_widgets_table', 1),
    ('2026_02_12_000001_add_authorization_fields', 1),
    ('2026_02_12_000002_add_missing_indexes_and_constraints', 1),
    ('2026_02_13_000001_create_site_presets_table', 1),
    ('2026_02_13_000002_add_preset_columns_to_sites', 1),
    ('2026_02_13_000003_add_interval_minutes_to_uptime_monitors', 1),
    ('2026_02_13_000004_create_security_monitors_table', 1),
    ('2026_02_13_000005_create_database_cleanup_configs_table', 1),
    ('2026_02_13_000006_create_site_health_state_table', 1),
    ('2026_02_13_000007_add_dispatcher_columns', 1),
    ('2026_02_13_000008_backfill_dispatcher_data', 1),
    ('2026_02_13_000009_create_site_monthly_snapshots_table', 1),
    ('2026_02_13_000010_create_site_report_configs_table', 1),
    ('2026_02_13_000011_drop_legacy_interval_from_uptime_monitors', 1),
    ('2026_02_14_000001_drop_seo_checks_table_and_seo_score_column', 1),
    ('2026_02_16_000001_add_performance_indexes', 1),
    ('2026_02_16_000002_add_role_column_to_users_table', 1),
    ('2026_02_16_000003_create_site_preset_modules_table', 1),
    ('2026_02_16_152729_drop_maintenance_windows_table', 1),
    ('2026_02_16_160000_drop_orphaned_tables', 1),
    ('2026_02_17_000001_add_missing_indexes_to_clients_and_notification_channels', 1),
    ('2026_02_17_000002_add_saved_mb_columns_to_database_cleanups_table', 1),
    ('2026_02_18_000001_add_section_overrides_and_options_to_report_templates', 1),
    ('2026_02_18_120000_add_custom_recommendations_to_site_report_configs', 1),
    ('2026_02_20_000001_add_reminder_sent_at_to_report_schedules', 1),
    ('2026_02_20_000001_create_report_recommendations_table', 1),
    ('2026_02_20_000002_create_recommendation_templates_table', 1),
    ('2026_02_21_000001_drop_unused_framework_tables', 1),
    ('2026_02_21_000002_remove_has_woocommerce_from_sites', 1),
    ('2026_02_21_000003_add_indexes_for_scale', 1),
    ('2026_02_22_000001_add_retention_cleanup_indexes', 1),
    ('2026_02_22_000001_drop_interval_from_uptime_monitors', 1),
    ('2026_02_22_000002_add_database_constraints', 1),
    ('2026_02_22_100001_change_notification_channels_config_to_text', 1),
    ('2026_03_03_000001_create_security_hardening_tables', 1),
    ('2026_03_03_000002_add_security_score_to_sites', 1),
    ('2026_03_03_000003_add_security_columns_to_site_users', 1),
    ('2026_03_08_000001_add_audit_indexes', 1),
    ('2026_03_12_000001_add_upload_method_to_backups', 1),
    ('2026_03_16_000001_add_backup_capabilities_to_sites', 1),
    ('2026_03_16_000002_add_preparation_method_to_backups', 1),
    ('2026_03_17_000001_add_incremental_backup_support', 1),
    ('2026_03_17_000002_add_incremental_schedule_to_backup_configs', 1),
    ('2026_03_18_000001_drop_exclude_columns_from_backup_configs', 1),
    ('2026_03_20_000001_add_event_category_to_security_activity_logs', 1),
    ('2026_03_20_000002_add_custom_login_slug_to_sites', 1),
    ('2026_03_20_000003_add_settings_columns_to_site_presets', 1),
    ('2026_03_20_000004_add_config_to_site_preset_modules', 1),
    ('2026_03_20_000005_rename_site_presets_to_maintenance_plans', 1),
    ('2026_03_21_000001_fix_notification_channels_config_column', 1),
    ('2026_03_22_000001_add_connector_version_to_sites_table', 1),
    ('2026_03_22_000002_drop_ssl_and_domain_tables', 1),
    ('2026_03_24_000001_add_cancelled_to_backups_status_check', 1),
    ('2026_03_28_000001_add_maintenance_window_to_uptime_monitors', 1),
    ('2026_03_28_000002_create_status_page_incident_templates_table', 1),
    ('2026_03_28_000003_create_invitations_table', 1),
    ('2026_03_28_000004_create_personal_access_tokens_table', 1),
    ('2026_03_28_000005_create_notification_templates_table', 1),
    ('2026_03_28_000006_add_sla_target_to_status_pages', 1),
    ('2026_03_28_000007_add_check_location_to_uptime', 1),
    ('2026_03_28_000008_create_client_user_table', 1),
    ('2026_03_28_000009_add_license_fields_to_site_plugins', 1),
    ('2026_03_28_000010_add_encrypt_backups_to_backup_configs', 1),
    ('2026_03_28_000011_add_portal_token_to_clients', 1),
    ('2026_03_28_000012_add_escalation_support', 1),
    ('2026_03_28_000013_add_google_id_to_users', 1),
    ('2026_03_28_000014_add_competitor_benchmarking', 1),
    ('2026_03_30_000001_add_orders_count_to_site_users', 1),
    ('2026_03_31_000001_add_report_template_id_to_sites', 1),
    ('2026_03_31_000002_add_view_token_to_reports', 1),
    ('2026_03_31_000003_add_language_to_report_templates', 1),
    ('2026_03_31_000004_drop_dead_columns_from_schedules_and_configs', 1),
    ('2026_03_31_000005_enable_portal_by_default', 1),
    ('2026_04_01_000001_add_failure_tracking_to_report_schedules', 1),
    ('2026_04_03_000001_add_wp_admin_user_id_to_sites', 1),
    ('2026_04_04_000001_convert_json_columns_to_jsonb', 1),
    ('2026_04_04_100001_drop_email_health_checks_table', 1),
    ('2026_04_05_000001_create_incident_responses_table', 1),
    ('2026_04_05_000002_create_incident_response_actions_table', 1),
    ('2026_04_05_100001_add_company_details_to_clients_table', 1),
    ('2026_04_09_000001_create_seo_monitors_table', 1),
    ('2026_04_09_000002_create_seo_audits_table', 1),
    ('2026_04_09_000003_create_seo_issues_table', 1),
    ('2026_04_09_000004_add_seo_columns_to_site_monthly_snapshots', 1),
    ('2026_04_09_100001_create_site_crawls_table', 1),
    ('2026_04_09_100002_create_crawled_pages_table', 1),
    ('2026_04_10_000001_alter_crawled_pages_widen_string_columns', 1),
    ('2026_04_10_100001_create_seo_contents_table', 1),
    ('2026_04_10_100002_create_seo_content_revisions_table', 1),
    ('2026_04_10_100003_create_keyword_research_results_table', 1),
    ('2026_04_10_100004_add_crawl_schedule_to_seo_monitors', 1),
    ('2026_04_10_100005_add_images_column_to_crawled_pages', 1),
    ('2026_04_10_100006_alter_site_crawls_for_standalone', 1),
    ('2026_04_10_100007_add_resources_columns_to_crawled_pages', 1),
    ('2026_04_10_100008_add_ai_provider_to_seo_contents', 1),
    ('2026_04_10_100009_add_ai_context_to_sites', 1),
    ('2026_04_10_200001_add_theme_to_users', 1),
    ('2026_04_10_200002_create_in_app_notifications_table', 1),
    ('2026_04_11_100001_create_theme_file_checks_table', 1),
    ('2026_04_12_000001_create_seo_alert_rules_table', 1),
    ('2026_04_12_000002_create_keyword_page_mappings_table', 1),
    ('2026_04_12_000003_add_brand_flag_to_tracked_keywords', 1),
    ('2026_04_12_100001_create_backlinks_table', 1),
    ('2026_04_12_100002_create_backlink_snapshots_table', 1),
    ('2026_04_12_200001_add_h4_h6_to_crawled_pages', 1),
    ('2026_04_12_300001_create_competitor_sites_table', 1),
    ('2026_04_12_300002_create_competitor_keyword_positions_table', 1),
    ('2026_04_12_400001_add_unique_url_to_crawled_pages', 1),
    ('2026_04_12_400002_add_spam_score_to_backlinks', 1),
    ('2026_04_12_500001_add_detail_fields_to_backlinks', 1),
    ('2026_04_12_600001_add_ranking_to_seo_contents', 1),
    ('2026_04_12_700001_add_visual_regression_to_safe_updates', 1),
    ('2026_04_12_800001_add_ai_risk_to_safe_updates', 1),
    ('2026_04_12_800002_create_site_contents_table', 1),
    ('2026_04_12_800003_create_client_costs_and_revenues_tables', 1),
    ('2026_04_12_800004_create_php_error_logs_table', 1),
    ('2026_04_12_800005_create_dns_monitors_and_changes_tables', 1),
    ('2026_04_12_900001_create_notification_event_preferences_table', 1),
    ('2026_04_12_900002_create_health_score_history_table', 1),
    ('2026_04_12_950001_create_seo_pages_table', 1),
    ('2026_04_12_950002_create_seo_links_table', 1),
    ('2026_04_12_950003_add_audit_config_to_seo_monitors', 1),
    ('2026_04_12_950004_add_status_columns_to_seo_audits', 1),
    ('2026_04_13_000001_add_is_prospect_to_sites_table', 1),
    ('2026_04_13_100001_drop_site_contents_table', 1),
    ('2026_04_14_000001_create_seo_images_table', 1),
    ('2026_04_14_000002_add_resource_counts_to_seo_audits', 1),
    ('2026_04_14_100001_add_auto_retry_count_to_backups', 1),
    ('2026_04_14_100001_create_seo_keyword_rankings_table', 1),
    ('2026_05_01_000001_add_dedup_index_to_reports', 1),
    ('2026_05_10_093212_drop_encryption_columns_from_backups', 1),
    ('2026_05_10_094232_add_verification_columns_to_backups', 1),
    ('2026_05_10_095308_add_replica_columns', 1),
    ('2026_05_10_103111_add_streaming_columns', 1);


