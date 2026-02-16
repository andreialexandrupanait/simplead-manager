--
-- PostgreSQL database dump
--

\restrict r5eq3YHVQRJovog408rpeD4aSNOUSVBmrcXY43k9gAchS6V7JbNhRPn1xuE6Gxh

-- Dumped from database version 16.11
-- Dumped by pg_dump version 16.11

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

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
    metadata json,
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
    data json NOT NULL,
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
    components json,
    storage_destination_id bigint,
    retention_type character varying(255) DEFAULT 'count'::character varying NOT NULL,
    retention_value integer DEFAULT 7 NOT NULL,
    encrypt_backup boolean DEFAULT false NOT NULL,
    encryption_password text,
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
    components json,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    progress smallint DEFAULT '0'::smallint NOT NULL,
    error_message text,
    log json,
    storage_destination_id bigint,
    storage_path character varying(255),
    file_name character varying(255),
    file_size bigint,
    checksum character varying(255),
    component_sizes json,
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
    updated_at timestamp(0) without time zone
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
    restore_error_message text
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
-- Name: blocked_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.blocked_requests (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    ip_rule_id bigint,
    ip_address character varying(255) NOT NULL,
    request_url character varying(255),
    user_agent text,
    blocked_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: blocked_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.blocked_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: blocked_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.blocked_requests_id_seq OWNED BY public.blocked_requests.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


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
    deleted_at timestamp(0) without time zone
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
    targets json,
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
    modified_files json,
    missing_files json,
    unknown_files json,
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
-- Name: dashboard_widgets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dashboard_widgets (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    widget_type character varying(50) NOT NULL,
    config json,
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
    auto_clean_types json,
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
    updated_at timestamp(0) without time zone
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
    tables_data json,
    largest_tables json,
    tables_with_overhead json,
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
-- Name: dns_records_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dns_records_cache (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    domain character varying(255) NOT NULL,
    a_records json,
    aaaa_records json,
    cname_records json,
    mx_records json,
    txt_records json,
    ns_records json,
    soa_record json,
    has_www boolean DEFAULT false NOT NULL,
    uses_cloudflare boolean DEFAULT false NOT NULL,
    has_spf boolean DEFAULT false NOT NULL,
    has_dmarc boolean DEFAULT false NOT NULL,
    has_dkim boolean DEFAULT false NOT NULL,
    mail_provider character varying(255),
    email_security_score integer DEFAULT 0 NOT NULL,
    total_records integer DEFAULT 0 NOT NULL,
    checked_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dns_records_cache_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dns_records_cache_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dns_records_cache_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dns_records_cache_id_seq OWNED BY public.dns_records_cache.id;


--
-- Name: domain_check_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.domain_check_history (
    id bigint NOT NULL,
    domain_monitor_id bigint NOT NULL,
    status character varying(255) NOT NULL,
    days_remaining integer,
    registrar character varying(255),
    nameservers json,
    error_message text,
    checked_at timestamp(0) without time zone NOT NULL
);


--
-- Name: domain_check_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.domain_check_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: domain_check_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.domain_check_history_id_seq OWNED BY public.domain_check_history.id;


--
-- Name: domain_monitors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.domain_monitors (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    domain character varying(255) NOT NULL,
    tld character varying(255),
    registrar character varying(255),
    registrar_url character varying(255),
    registered_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    days_remaining integer,
    nameservers json,
    dns_provider character varying(255),
    domain_statuses json,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    error_message text,
    alerts_enabled boolean DEFAULT true NOT NULL,
    warn_days integer DEFAULT 30 NOT NULL,
    last_alert_sent_at timestamp(0) without time zone,
    last_checked_at timestamp(0) without time zone,
    next_check_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone
);


--
-- Name: domain_monitors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.domain_monitors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: domain_monitors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.domain_monitors_id_seq OWNED BY public.domain_monitors.id;


--
-- Name: email_health_checks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.email_health_checks (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    domain character varying(255) NOT NULL,
    spf_exists boolean DEFAULT false NOT NULL,
    spf_record text,
    spf_status character varying(255),
    spf_issues json,
    dkim_exists boolean DEFAULT false NOT NULL,
    dkim_selector character varying(255),
    dkim_status character varying(255),
    dmarc_exists boolean DEFAULT false NOT NULL,
    dmarc_record text,
    dmarc_policy character varying(255),
    dmarc_status character varying(255),
    blacklists_checked json,
    blacklists_clean integer DEFAULT 0 NOT NULL,
    blacklists_listed integer DEFAULT 0 NOT NULL,
    mx_records json,
    score integer DEFAULT 0 NOT NULL,
    status character varying(255) DEFAULT 'unknown'::character varying NOT NULL,
    checked_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: email_health_checks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.email_health_checks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: email_health_checks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.email_health_checks_id_seq OWNED BY public.email_health_checks.id;


--
-- Name: error_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.error_logs (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    error_hash character varying(255) NOT NULL,
    level character varying(255) NOT NULL,
    message text NOT NULL,
    file_path character varying(255),
    line_number integer,
    stack_trace text,
    context json,
    count integer DEFAULT 1 NOT NULL,
    first_seen_at timestamp(0) without time zone,
    last_seen_at timestamp(0) without time zone,
    is_resolved boolean DEFAULT false NOT NULL,
    resolved_by bigint,
    resolved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: error_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.error_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: error_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.error_logs_id_seq OWNED BY public.error_logs.id;


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
    scopes json,
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
-- Name: ip_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ip_rules (
    id bigint NOT NULL,
    site_id bigint,
    ip_address character varying(255) NOT NULL,
    type character varying(255) NOT NULL,
    reason character varying(255),
    expires_at timestamp(0) without time zone,
    created_by bigint,
    hits_count integer DEFAULT 0 NOT NULL,
    last_hit_at timestamp(0) without time zone,
    is_synced boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ip_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ip_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ip_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ip_rules_id_seq OWNED BY public.ip_rules.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


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
-- Name: link_monitors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.link_monitors (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    frequency character varying(255) DEFAULT 'weekly'::character varying NOT NULL,
    scan_time character varying(255) DEFAULT '02:00'::character varying NOT NULL,
    day_of_week smallint,
    max_pages integer DEFAULT 200 NOT NULL,
    max_depth smallint DEFAULT '5'::smallint NOT NULL,
    check_external boolean DEFAULT true NOT NULL,
    check_images boolean DEFAULT true NOT NULL,
    timeout_seconds smallint DEFAULT '30'::smallint NOT NULL,
    exclude_paths json,
    exclude_domains json,
    alert_on_broken boolean DEFAULT true NOT NULL,
    alert_threshold integer DEFAULT 1 NOT NULL,
    total_links integer DEFAULT 0 NOT NULL,
    broken_links integer DEFAULT 0 NOT NULL,
    redirects integer DEFAULT 0 NOT NULL,
    pages_scanned integer DEFAULT 0 NOT NULL,
    last_scan_at timestamp(0) without time zone,
    next_scan_at timestamp(0) without time zone,
    last_scan_status character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: link_monitors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.link_monitors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: link_monitors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.link_monitors_id_seq OWNED BY public.link_monitors.id;


--
-- Name: link_scans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.link_scans (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    link_monitor_id bigint NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    trigger character varying(255) DEFAULT 'manual'::character varying NOT NULL,
    total_links integer DEFAULT 0 NOT NULL,
    broken_links integer DEFAULT 0 NOT NULL,
    redirects integer DEFAULT 0 NOT NULL,
    timeouts integer DEFAULT 0 NOT NULL,
    pages_scanned integer DEFAULT 0 NOT NULL,
    progress_percent smallint DEFAULT '0'::smallint NOT NULL,
    progress_message character varying(255),
    error_message text,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    duration_seconds integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: link_scans_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.link_scans_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: link_scans_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.link_scans_id_seq OWNED BY public.link_scans.id;


--
-- Name: links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.links (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    link_scan_id bigint NOT NULL,
    url character varying(2048) NOT NULL,
    url_hash character varying(64) NOT NULL,
    type character varying(255) DEFAULT 'internal'::character varying NOT NULL,
    link_type character varying(255) DEFAULT 'anchor'::character varying NOT NULL,
    source_url character varying(2048),
    source_title character varying(255),
    anchor_text character varying(500),
    element character varying(255),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    http_code smallint,
    final_url character varying(2048),
    redirect_count smallint DEFAULT '0'::smallint NOT NULL,
    response_time_ms integer,
    error_message text,
    is_permanent_redirect boolean DEFAULT false NOT NULL,
    is_dismissed boolean DEFAULT false NOT NULL,
    dismissed_reason character varying(255),
    first_detected_at timestamp(0) without time zone,
    last_checked_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: links_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.links_id_seq OWNED BY public.links.id;


--
-- Name: maintenance_windows; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.maintenance_windows (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    user_id bigint,
    title character varying(255) NOT NULL,
    description text,
    scheduled_start_at timestamp(0) without time zone NOT NULL,
    scheduled_end_at timestamp(0) without time zone NOT NULL,
    actual_start_at timestamp(0) without time zone,
    actual_end_at timestamp(0) without time zone,
    status character varying(255) DEFAULT 'scheduled'::character varying NOT NULL,
    pause_uptime boolean DEFAULT true NOT NULL,
    pause_ssl boolean DEFAULT false NOT NULL,
    pause_performance boolean DEFAULT false NOT NULL,
    pause_backups boolean DEFAULT false NOT NULL,
    pause_links boolean DEFAULT false NOT NULL,
    notify_on_start boolean DEFAULT true NOT NULL,
    notify_on_end boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    update_status_page boolean DEFAULT false NOT NULL
);


--
-- Name: maintenance_windows_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.maintenance_windows_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: maintenance_windows_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.maintenance_windows_id_seq OWNED BY public.maintenance_windows.id;


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
    config json NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    last_used_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    event_subscriptions json,
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
    metadata json,
    response_code integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
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
    budgets json,
    interval_minutes integer DEFAULT 10080 NOT NULL
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
    opportunities json,
    diagnostics json,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    error_message text,
    lighthouse_version character varying(255),
    tested_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    performance_page_id bigint,
    third_party_scripts json,
    dom_elements integer,
    dom_max_depth integer,
    dom_max_children integer,
    unused_js_bytes integer,
    unused_css_bytes integer,
    unused_js_details json,
    unused_css_details json,
    image_audit json,
    wp_health_checks json,
    screenshot_final text,
    filmstrip json
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
    recipient_emails json,
    send_copy_to_admin boolean DEFAULT true NOT NULL,
    email_subject character varying(255),
    email_body text,
    client_name character varying(255),
    client_logo_path character varying(255),
    last_generated_at timestamp(0) without time zone,
    last_sent_at timestamp(0) without time zone,
    next_run_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
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
    sections json NOT NULL,
    company_name character varying(255),
    company_logo_path character varying(255),
    company_website character varying(255),
    primary_color character varying(7) DEFAULT '#7C3AED'::character varying NOT NULL,
    intro_text text,
    closing_text text,
    is_default boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
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
    sent_to json,
    data_snapshot json,
    generated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
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
-- Name: resource_checks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.resource_checks (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    cpu_usage numeric(5,2),
    memory_used bigint,
    memory_total bigint,
    memory_percentage numeric(5,2),
    disk_used bigint,
    disk_total bigint,
    disk_percentage numeric(5,2),
    load_average_1 numeric(5,2),
    load_average_5 numeric(5,2),
    load_average_15 numeric(5,2),
    is_available boolean DEFAULT true NOT NULL,
    checked_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: resource_checks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.resource_checks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: resource_checks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.resource_checks_id_seq OWNED BY public.resource_checks.id;


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
    CONSTRAINT rollback_points_status_check CHECK (((status)::text = ANY ((ARRAY['available'::character varying, 'used'::character varying, 'expired'::character varying])::text[]))),
    CONSTRAINT rollback_points_type_check CHECK (((type)::text = ANY ((ARRAY['plugin'::character varying, 'theme'::character varying, 'core'::character varying])::text[])))
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
    health_check_results json,
    error_message text,
    auto_rollback boolean DEFAULT true NOT NULL,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT safe_updates_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'backing_up'::character varying, 'updating'::character varying, 'health_checking'::character varying, 'rolling_back'::character varying, 'completed'::character varying, 'failed'::character varying])::text[]))),
    CONSTRAINT safe_updates_type_check CHECK (((type)::text = ANY ((ARRAY['plugin'::character varying, 'theme'::character varying, 'core'::character varying])::text[])))
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
    data json NOT NULL,
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
    scores_breakdown json,
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
-- Name: seo_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_alerts (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    type character varying(30) NOT NULL,
    severity character varying(10) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    metadata json,
    is_active boolean DEFAULT true NOT NULL,
    detected_at timestamp(0) without time zone NOT NULL,
    resolved_at timestamp(0) without time zone,
    dismissed_at timestamp(0) without time zone,
    dismissed_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: seo_alerts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_alerts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_alerts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_alerts_id_seq OWNED BY public.seo_alerts.id;


--
-- Name: seo_configs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_configs (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    is_active boolean DEFAULT false NOT NULL,
    sync_frequency character varying(10) DEFAULT 'daily'::character varying NOT NULL,
    next_sync_at timestamp(0) without time zone,
    last_synced_at timestamp(0) without time zone,
    alert_keyword_drop_threshold smallint DEFAULT '5'::smallint NOT NULL,
    alert_traffic_drop_threshold smallint DEFAULT '30'::smallint NOT NULL,
    alert_position_drop_threshold numeric(4,1) DEFAULT '3'::numeric NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: seo_configs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_configs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_configs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_configs_id_seq OWNED BY public.seo_configs.id;


--
-- Name: seo_pinned_keywords; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_pinned_keywords (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    keyword character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: seo_pinned_keywords_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_pinned_keywords_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_pinned_keywords_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_pinned_keywords_id_seq OWNED BY public.seo_pinned_keywords.id;


--
-- Name: seo_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_snapshots (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    date date NOT NULL,
    total_clicks integer DEFAULT 0 NOT NULL,
    total_impressions integer DEFAULT 0 NOT NULL,
    avg_ctr numeric(6,4),
    avg_position numeric(6,2),
    clicks_change_7d integer,
    clicks_change_pct_7d numeric(6,2),
    position_change_7d numeric(6,2),
    indexed_pages integer,
    excluded_pages integer,
    coverage_errors_404 integer DEFAULT 0 NOT NULL,
    coverage_errors_redirect integer DEFAULT 0 NOT NULL,
    coverage_errors_server integer DEFAULT 0 NOT NULL,
    coverage_blocked_robotstxt integer DEFAULT 0 NOT NULL,
    coverage_noindex integer DEFAULT 0 NOT NULL,
    has_manual_action boolean DEFAULT false NOT NULL,
    seo_health_score smallint,
    score_traffic smallint,
    score_position smallint,
    score_index smallint,
    score_coverage smallint,
    score_pagespeed_seo smallint,
    pagespeed_seo_score smallint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: seo_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_snapshots_id_seq OWNED BY public.seo_snapshots.id;


--
-- Name: seo_top_pages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_top_pages (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    date date NOT NULL,
    page_url character varying(2048) NOT NULL,
    clicks integer DEFAULT 0 NOT NULL,
    impressions integer DEFAULT 0 NOT NULL,
    ctr numeric(6,4),
    "position" numeric(6,2),
    previous_clicks_7d integer,
    clicks_change_pct numeric(6,2),
    is_traffic_drop boolean DEFAULT false NOT NULL,
    is_low_ctr boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: seo_top_pages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_top_pages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_top_pages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_top_pages_id_seq OWNED BY public.seo_top_pages.id;


--
-- Name: seo_top_queries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_top_queries (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    date date NOT NULL,
    query character varying(500) NOT NULL,
    clicks integer DEFAULT 0 NOT NULL,
    impressions integer DEFAULT 0 NOT NULL,
    ctr numeric(6,4),
    "position" numeric(6,2),
    previous_position numeric(6,2),
    position_change numeric(6,2),
    rank_type character varying(20) NOT NULL,
    is_pinned boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: seo_top_queries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_top_queries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_top_queries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_top_queries_id_seq OWNED BY public.seo_top_queries.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


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
-- Name: site_cron_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_cron_jobs (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    hook character varying(255) NOT NULL,
    schedule character varying(255),
    "interval" integer,
    next_run timestamp(0) without time zone,
    last_run timestamp(0) without time zone,
    arguments json,
    is_disabled boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_cron_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_cron_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_cron_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_cron_jobs_id_seq OWNED BY public.site_cron_jobs.id;


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
    seo_health_score_avg integer,
    seo_total_clicks integer,
    seo_total_impressions integer,
    seo_avg_position numeric(6,2),
    seo_indexed_pages_end integer,
    seo_coverage_errors_end integer
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
    abandoned_checked_at timestamp(0) without time zone
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
-- Name: site_preset_modules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_preset_modules (
    id bigint NOT NULL,
    site_preset_id bigint NOT NULL,
    module_key character varying(255) NOT NULL,
    is_enabled boolean DEFAULT false NOT NULL,
    interval_minutes integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


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

ALTER SEQUENCE public.site_preset_modules_id_seq OWNED BY public.site_preset_modules.id;


--
-- Name: site_presets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_presets (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255),
    modules json,
    is_default boolean DEFAULT false NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


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

ALTER SEQUENCE public.site_presets_id_seq OWNED BY public.site_presets.id;


--
-- Name: site_report_configs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_report_configs (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    language character varying(255) DEFAULT 'en'::character varying NOT NULL,
    show_security boolean DEFAULT true NOT NULL,
    show_cloudflare boolean DEFAULT false NOT NULL,
    custom_notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    show_seo boolean DEFAULT true NOT NULL
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
    updated_at timestamp(0) without time zone
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
    ssl_ok boolean DEFAULT true NOT NULL,
    ssl_expiry date,
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
    has_woocommerce boolean DEFAULT false NOT NULL,
    favicon_path character varying(255),
    screenshot_path character varying(255),
    user_id bigint,
    applied_preset_id bigint,
    is_preset_customized boolean DEFAULT false NOT NULL
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
-- Name: ssl_certificates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ssl_certificates (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    domain character varying(255) NOT NULL,
    issuer character varying(255),
    issuer_organisation character varying(255),
    san_domains json,
    signature_algorithm character varying(255),
    key_size integer,
    protocol character varying(255),
    cipher character varying(255),
    issued_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    days_remaining integer,
    chain_valid boolean DEFAULT false NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    error_message text,
    handshake_time integer,
    alerts_enabled boolean DEFAULT true NOT NULL,
    warn_days integer DEFAULT 30 NOT NULL,
    last_alert_sent_at timestamp(0) without time zone,
    last_checked_at timestamp(0) without time zone,
    next_check_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ssl_certificates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ssl_certificates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ssl_certificates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ssl_certificates_id_seq OWNED BY public.ssl_certificates.id;


--
-- Name: ssl_check_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ssl_check_history (
    id bigint NOT NULL,
    ssl_certificate_id bigint NOT NULL,
    status character varying(255) NOT NULL,
    days_remaining integer,
    issuer character varying(255),
    protocol character varying(255),
    cipher character varying(255),
    chain_valid boolean DEFAULT false NOT NULL,
    handshake_time integer,
    error_message text,
    checked_at timestamp(0) without time zone NOT NULL
);


--
-- Name: ssl_check_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ssl_check_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ssl_check_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ssl_check_history_id_seq OWNED BY public.ssl_check_history.id;


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
    updated_at timestamp(0) without time zone
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
    config json,
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
-- Name: tracked_keywords; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tracked_keywords (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    keyword character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
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
    checked_at timestamp(0) without time zone NOT NULL
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
    notified_via json,
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
    http_headers json,
    http_body text,
    accepted_status_codes json,
    follow_redirects boolean DEFAULT true NOT NULL,
    auth_type character varying(255),
    auth_username character varying(255),
    auth_password text,
    auth_token text,
    keyword character varying(255),
    keyword_type character varying(255),
    keyword_case_sensitive boolean DEFAULT false NOT NULL,
    check_ssl boolean DEFAULT true NOT NULL,
    ssl_expiry_threshold integer DEFAULT 14 NOT NULL,
    alert_after_failures integer DEFAULT 3 NOT NULL,
    alert_contacts json,
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
    interval_minutes integer DEFAULT 5 NOT NULL
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
    is_admin boolean DEFAULT false NOT NULL
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
    "references" json,
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
-- Name: woocommerce_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.woocommerce_alerts (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    product_id integer,
    product_name character varying(255),
    message text NOT NULL,
    is_acknowledged boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT woocommerce_alerts_type_check CHECK (((type)::text = ANY ((ARRAY['low_stock'::character varying, 'out_of_stock'::character varying, 'failed_order'::character varying, 'high_refunds'::character varying])::text[])))
);


--
-- Name: woocommerce_alerts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.woocommerce_alerts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: woocommerce_alerts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.woocommerce_alerts_id_seq OWNED BY public.woocommerce_alerts.id;


--
-- Name: woocommerce_stats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.woocommerce_stats (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    date date NOT NULL,
    orders_count integer DEFAULT 0 NOT NULL,
    revenue numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    currency character varying(3) DEFAULT 'USD'::character varying NOT NULL,
    average_order_value numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    products_sold_count integer DEFAULT 0 NOT NULL,
    refunds_count integer DEFAULT 0 NOT NULL,
    refunds_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    new_customers integer DEFAULT 0 NOT NULL,
    returning_customers integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: woocommerce_stats_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.woocommerce_stats_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: woocommerce_stats_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.woocommerce_stats_id_seq OWNED BY public.woocommerce_stats.id;


--
-- Name: wp_audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.wp_audit_logs (
    id bigint NOT NULL,
    site_id bigint NOT NULL,
    wp_user_id integer,
    wp_username character varying(255),
    user_role character varying(255),
    action_type character varying(255) NOT NULL,
    object_type character varying(255),
    object_id character varying(255),
    object_title character varying(255),
    old_value json,
    new_value json,
    ip_address character varying(255),
    user_agent text,
    action_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: wp_audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.wp_audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: wp_audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.wp_audit_logs_id_seq OWNED BY public.wp_audit_logs.id;


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
-- Name: backup_configs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_configs ALTER COLUMN id SET DEFAULT nextval('public.backup_configs_id_seq'::regclass);


--
-- Name: backups id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backups ALTER COLUMN id SET DEFAULT nextval('public.backups_id_seq'::regclass);


--
-- Name: blocked_requests id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blocked_requests ALTER COLUMN id SET DEFAULT nextval('public.blocked_requests_id_seq'::regclass);


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
-- Name: core_file_checks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_file_checks ALTER COLUMN id SET DEFAULT nextval('public.core_file_checks_id_seq'::regclass);


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
-- Name: dns_records_cache id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_records_cache ALTER COLUMN id SET DEFAULT nextval('public.dns_records_cache_id_seq'::regclass);


--
-- Name: domain_check_history id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_check_history ALTER COLUMN id SET DEFAULT nextval('public.domain_check_history_id_seq'::regclass);


--
-- Name: domain_monitors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_monitors ALTER COLUMN id SET DEFAULT nextval('public.domain_monitors_id_seq'::regclass);


--
-- Name: email_health_checks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_health_checks ALTER COLUMN id SET DEFAULT nextval('public.email_health_checks_id_seq'::regclass);


--
-- Name: error_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.error_logs ALTER COLUMN id SET DEFAULT nextval('public.error_logs_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: google_connections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.google_connections ALTER COLUMN id SET DEFAULT nextval('public.google_connections_id_seq'::regclass);


--
-- Name: ip_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ip_rules ALTER COLUMN id SET DEFAULT nextval('public.ip_rules_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: keyword_positions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_positions ALTER COLUMN id SET DEFAULT nextval('public.keyword_positions_id_seq'::regclass);


--
-- Name: link_monitors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.link_monitors ALTER COLUMN id SET DEFAULT nextval('public.link_monitors_id_seq'::regclass);


--
-- Name: link_scans id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.link_scans ALTER COLUMN id SET DEFAULT nextval('public.link_scans_id_seq'::regclass);


--
-- Name: links id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.links ALTER COLUMN id SET DEFAULT nextval('public.links_id_seq'::regclass);


--
-- Name: maintenance_windows id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_windows ALTER COLUMN id SET DEFAULT nextval('public.maintenance_windows_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: notification_channels id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_channels ALTER COLUMN id SET DEFAULT nextval('public.notification_channels_id_seq'::regclass);


--
-- Name: notification_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_logs ALTER COLUMN id SET DEFAULT nextval('public.notification_logs_id_seq'::regclass);


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
-- Name: plugin_conflicts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plugin_conflicts ALTER COLUMN id SET DEFAULT nextval('public.plugin_conflicts_id_seq'::regclass);


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
-- Name: resource_checks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.resource_checks ALTER COLUMN id SET DEFAULT nextval('public.resource_checks_id_seq'::regclass);


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
-- Name: security_issues id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_issues ALTER COLUMN id SET DEFAULT nextval('public.security_issues_id_seq'::regclass);


--
-- Name: security_monitors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_monitors ALTER COLUMN id SET DEFAULT nextval('public.security_monitors_id_seq'::regclass);


--
-- Name: security_recommendations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_recommendations ALTER COLUMN id SET DEFAULT nextval('public.security_recommendations_id_seq'::regclass);


--
-- Name: security_scans id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_scans ALTER COLUMN id SET DEFAULT nextval('public.security_scans_id_seq'::regclass);


--
-- Name: seo_alerts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_alerts ALTER COLUMN id SET DEFAULT nextval('public.seo_alerts_id_seq'::regclass);


--
-- Name: seo_configs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_configs ALTER COLUMN id SET DEFAULT nextval('public.seo_configs_id_seq'::regclass);


--
-- Name: seo_pinned_keywords id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_pinned_keywords ALTER COLUMN id SET DEFAULT nextval('public.seo_pinned_keywords_id_seq'::regclass);


--
-- Name: seo_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_snapshots ALTER COLUMN id SET DEFAULT nextval('public.seo_snapshots_id_seq'::regclass);


--
-- Name: seo_top_pages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_top_pages ALTER COLUMN id SET DEFAULT nextval('public.seo_top_pages_id_seq'::regclass);


--
-- Name: seo_top_queries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_top_queries ALTER COLUMN id SET DEFAULT nextval('public.seo_top_queries_id_seq'::regclass);


--
-- Name: site_cloudflare id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_cloudflare ALTER COLUMN id SET DEFAULT nextval('public.site_cloudflare_id_seq'::regclass);


--
-- Name: site_cron_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_cron_jobs ALTER COLUMN id SET DEFAULT nextval('public.site_cron_jobs_id_seq'::regclass);


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
-- Name: site_preset_modules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_preset_modules ALTER COLUMN id SET DEFAULT nextval('public.site_preset_modules_id_seq'::regclass);


--
-- Name: site_presets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_presets ALTER COLUMN id SET DEFAULT nextval('public.site_presets_id_seq'::regclass);


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
-- Name: ssl_certificates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ssl_certificates ALTER COLUMN id SET DEFAULT nextval('public.ssl_certificates_id_seq'::regclass);


--
-- Name: ssl_check_history id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ssl_check_history ALTER COLUMN id SET DEFAULT nextval('public.ssl_check_history_id_seq'::regclass);


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
-- Name: woocommerce_alerts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.woocommerce_alerts ALTER COLUMN id SET DEFAULT nextval('public.woocommerce_alerts_id_seq'::regclass);


--
-- Name: woocommerce_stats id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.woocommerce_stats ALTER COLUMN id SET DEFAULT nextval('public.woocommerce_stats_id_seq'::regclass);


--
-- Name: wp_audit_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wp_audit_logs ALTER COLUMN id SET DEFAULT nextval('public.wp_audit_logs_id_seq'::regclass);


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
-- Name: blocked_requests blocked_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blocked_requests
    ADD CONSTRAINT blocked_requests_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: clients clients_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clients
    ADD CONSTRAINT clients_pkey PRIMARY KEY (id);


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
-- Name: core_file_checks core_file_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_file_checks
    ADD CONSTRAINT core_file_checks_pkey PRIMARY KEY (id);


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
-- Name: dns_records_cache dns_records_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_records_cache
    ADD CONSTRAINT dns_records_cache_pkey PRIMARY KEY (id);


--
-- Name: dns_records_cache dns_records_cache_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_records_cache
    ADD CONSTRAINT dns_records_cache_site_id_unique UNIQUE (site_id);


--
-- Name: domain_check_history domain_check_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_check_history
    ADD CONSTRAINT domain_check_history_pkey PRIMARY KEY (id);


--
-- Name: domain_monitors domain_monitors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_monitors
    ADD CONSTRAINT domain_monitors_pkey PRIMARY KEY (id);


--
-- Name: email_health_checks email_health_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_health_checks
    ADD CONSTRAINT email_health_checks_pkey PRIMARY KEY (id);


--
-- Name: error_logs error_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.error_logs
    ADD CONSTRAINT error_logs_pkey PRIMARY KEY (id);


--
-- Name: error_logs error_logs_site_id_error_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.error_logs
    ADD CONSTRAINT error_logs_site_id_error_hash_unique UNIQUE (site_id, error_hash);


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
-- Name: ip_rules ip_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ip_rules
    ADD CONSTRAINT ip_rules_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


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
-- Name: link_monitors link_monitors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.link_monitors
    ADD CONSTRAINT link_monitors_pkey PRIMARY KEY (id);


--
-- Name: link_scans link_scans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.link_scans
    ADD CONSTRAINT link_scans_pkey PRIMARY KEY (id);


--
-- Name: links links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.links
    ADD CONSTRAINT links_pkey PRIMARY KEY (id);


--
-- Name: maintenance_windows maintenance_windows_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_windows
    ADD CONSTRAINT maintenance_windows_pkey PRIMARY KEY (id);


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
-- Name: notification_logs notification_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_logs
    ADD CONSTRAINT notification_logs_pkey PRIMARY KEY (id);


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
-- Name: plugin_conflicts plugin_conflicts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.plugin_conflicts
    ADD CONSTRAINT plugin_conflicts_pkey PRIMARY KEY (id);


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
-- Name: resource_checks resource_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.resource_checks
    ADD CONSTRAINT resource_checks_pkey PRIMARY KEY (id);


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
-- Name: seo_alerts seo_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_alerts
    ADD CONSTRAINT seo_alerts_pkey PRIMARY KEY (id);


--
-- Name: seo_configs seo_configs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_configs
    ADD CONSTRAINT seo_configs_pkey PRIMARY KEY (id);


--
-- Name: seo_configs seo_configs_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_configs
    ADD CONSTRAINT seo_configs_site_id_unique UNIQUE (site_id);


--
-- Name: seo_pinned_keywords seo_pinned_keywords_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_pinned_keywords
    ADD CONSTRAINT seo_pinned_keywords_pkey PRIMARY KEY (id);


--
-- Name: seo_pinned_keywords seo_pinned_keywords_site_id_keyword_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_pinned_keywords
    ADD CONSTRAINT seo_pinned_keywords_site_id_keyword_unique UNIQUE (site_id, keyword);


--
-- Name: seo_snapshots seo_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_snapshots
    ADD CONSTRAINT seo_snapshots_pkey PRIMARY KEY (id);


--
-- Name: seo_snapshots seo_snapshots_site_id_date_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_snapshots
    ADD CONSTRAINT seo_snapshots_site_id_date_unique UNIQUE (site_id, date);


--
-- Name: seo_top_pages seo_top_pages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_top_pages
    ADD CONSTRAINT seo_top_pages_pkey PRIMARY KEY (id);


--
-- Name: seo_top_queries seo_top_queries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_top_queries
    ADD CONSTRAINT seo_top_queries_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


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
-- Name: site_cron_jobs site_cron_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_cron_jobs
    ADD CONSTRAINT site_cron_jobs_pkey PRIMARY KEY (id);


--
-- Name: site_cron_jobs site_cron_jobs_site_id_hook_schedule_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_cron_jobs
    ADD CONSTRAINT site_cron_jobs_site_id_hook_schedule_unique UNIQUE (site_id, hook, schedule);


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
-- Name: site_preset_modules site_preset_modules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_preset_modules
    ADD CONSTRAINT site_preset_modules_pkey PRIMARY KEY (id);


--
-- Name: site_preset_modules site_preset_modules_site_preset_id_module_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_preset_modules
    ADD CONSTRAINT site_preset_modules_site_preset_id_module_key_unique UNIQUE (site_preset_id, module_key);


--
-- Name: site_presets site_presets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_presets
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
-- Name: ssl_certificates ssl_certificates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ssl_certificates
    ADD CONSTRAINT ssl_certificates_pkey PRIMARY KEY (id);


--
-- Name: ssl_check_history ssl_check_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ssl_check_history
    ADD CONSTRAINT ssl_check_history_pkey PRIMARY KEY (id);


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
-- Name: woocommerce_alerts woocommerce_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.woocommerce_alerts
    ADD CONSTRAINT woocommerce_alerts_pkey PRIMARY KEY (id);


--
-- Name: woocommerce_stats woocommerce_stats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.woocommerce_stats
    ADD CONSTRAINT woocommerce_stats_pkey PRIMARY KEY (id);


--
-- Name: woocommerce_stats woocommerce_stats_site_id_date_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.woocommerce_stats
    ADD CONSTRAINT woocommerce_stats_site_id_date_unique UNIQUE (site_id, date);


--
-- Name: wp_audit_logs wp_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wp_audit_logs
    ADD CONSTRAINT wp_audit_logs_pkey PRIMARY KEY (id);


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
-- Name: blocked_requests_site_id_ip_address_blocked_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blocked_requests_site_id_ip_address_blocked_at_index ON public.blocked_requests USING btree (site_id, ip_address, blocked_at);


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
-- Name: core_file_checks_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX core_file_checks_site_id_index ON public.core_file_checks USING btree (site_id);


--
-- Name: core_file_checks_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX core_file_checks_status_index ON public.core_file_checks USING btree (status);


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
-- Name: domain_check_history_checked_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domain_check_history_checked_at_index ON public.domain_check_history USING btree (checked_at);


--
-- Name: domain_check_history_domain_monitor_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domain_check_history_domain_monitor_id_index ON public.domain_check_history USING btree (domain_monitor_id);


--
-- Name: domain_monitors_days_remaining_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domain_monitors_days_remaining_index ON public.domain_monitors USING btree (days_remaining);


--
-- Name: domain_monitors_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domain_monitors_expires_at_index ON public.domain_monitors USING btree (expires_at);


--
-- Name: domain_monitors_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domain_monitors_site_id_index ON public.domain_monitors USING btree (site_id);


--
-- Name: domain_monitors_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX domain_monitors_status_index ON public.domain_monitors USING btree (status);


--
-- Name: email_health_checks_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX email_health_checks_site_id_index ON public.email_health_checks USING btree (site_id);


--
-- Name: error_logs_is_resolved_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_logs_is_resolved_index ON public.error_logs USING btree (is_resolved);


--
-- Name: error_logs_last_seen_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_logs_last_seen_at_index ON public.error_logs USING btree (last_seen_at);


--
-- Name: error_logs_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_logs_level_index ON public.error_logs USING btree (level);


--
-- Name: error_logs_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_logs_site_id_created_at_index ON public.error_logs USING btree (site_id, created_at);


--
-- Name: error_logs_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_logs_site_id_index ON public.error_logs USING btree (site_id);


--
-- Name: error_logs_site_id_last_seen_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_logs_site_id_last_seen_at_index ON public.error_logs USING btree (site_id, last_seen_at);


--
-- Name: idx_user_sort; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_user_sort ON public.dashboard_widgets USING btree (user_id, sort_order);


--
-- Name: ip_rules_site_id_type_ip_address_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ip_rules_site_id_type_ip_address_index ON public.ip_rules USING btree (site_id, type, ip_address);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: link_monitors_is_active_next_scan_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX link_monitors_is_active_next_scan_at_index ON public.link_monitors USING btree (is_active, next_scan_at);


--
-- Name: link_monitors_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX link_monitors_site_id_index ON public.link_monitors USING btree (site_id);


--
-- Name: link_scans_link_monitor_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX link_scans_link_monitor_id_created_at_index ON public.link_scans USING btree (link_monitor_id, created_at);


--
-- Name: links_link_scan_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX links_link_scan_id_status_index ON public.links USING btree (link_scan_id, status);


--
-- Name: links_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX links_site_id_status_index ON public.links USING btree (site_id, status);


--
-- Name: links_site_id_url_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX links_site_id_url_hash_index ON public.links USING btree (site_id, url_hash);


--
-- Name: links_url_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX links_url_hash_index ON public.links USING btree (url_hash);


--
-- Name: maintenance_windows_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX maintenance_windows_site_id_status_index ON public.maintenance_windows USING btree (site_id, status);


--
-- Name: maintenance_windows_status_scheduled_end_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX maintenance_windows_status_scheduled_end_at_index ON public.maintenance_windows USING btree (status, scheduled_end_at);


--
-- Name: maintenance_windows_status_scheduled_start_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX maintenance_windows_status_scheduled_start_at_index ON public.maintenance_windows USING btree (status, scheduled_start_at);


--
-- Name: notification_channels_is_active_is_default_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_channels_is_active_is_default_index ON public.notification_channels USING btree (is_active, is_default);


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
-- Name: plugin_conflicts_plugin_a_slug_plugin_b_slug_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX plugin_conflicts_plugin_a_slug_plugin_b_slug_index ON public.plugin_conflicts USING btree (plugin_a_slug, plugin_b_slug);


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
-- Name: resource_checks_site_id_checked_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX resource_checks_site_id_checked_at_index ON public.resource_checks USING btree (site_id, checked_at);


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
-- Name: seo_alerts_site_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_alerts_site_id_is_active_index ON public.seo_alerts USING btree (site_id, is_active);


--
-- Name: seo_alerts_site_id_type_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_alerts_site_id_type_is_active_index ON public.seo_alerts USING btree (site_id, type, is_active);


--
-- Name: seo_configs_is_active_next_sync_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_configs_is_active_next_sync_at_index ON public.seo_configs USING btree (is_active, next_sync_at);


--
-- Name: seo_top_pages_site_id_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_top_pages_site_id_date_index ON public.seo_top_pages USING btree (site_id, date);


--
-- Name: seo_top_pages_site_id_date_is_low_ctr_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_top_pages_site_id_date_is_low_ctr_index ON public.seo_top_pages USING btree (site_id, date, is_low_ctr);


--
-- Name: seo_top_pages_site_id_date_is_traffic_drop_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_top_pages_site_id_date_is_traffic_drop_index ON public.seo_top_pages USING btree (site_id, date, is_traffic_drop);


--
-- Name: seo_top_queries_site_id_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_top_queries_site_id_date_index ON public.seo_top_queries USING btree (site_id, date);


--
-- Name: seo_top_queries_site_id_date_rank_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX seo_top_queries_site_id_date_rank_type_index ON public.seo_top_queries USING btree (site_id, date, rank_type);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: site_cloudflare_is_active_next_sync_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_cloudflare_is_active_next_sync_at_index ON public.site_cloudflare USING btree (is_active, next_sync_at);


--
-- Name: site_cron_jobs_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_cron_jobs_site_id_index ON public.site_cron_jobs USING btree (site_id);


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
-- Name: ssl_certificates_days_remaining_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ssl_certificates_days_remaining_index ON public.ssl_certificates USING btree (days_remaining);


--
-- Name: ssl_certificates_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ssl_certificates_expires_at_index ON public.ssl_certificates USING btree (expires_at);


--
-- Name: ssl_certificates_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ssl_certificates_site_id_index ON public.ssl_certificates USING btree (site_id);


--
-- Name: ssl_certificates_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ssl_certificates_status_index ON public.ssl_certificates USING btree (status);


--
-- Name: ssl_check_history_checked_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ssl_check_history_checked_at_index ON public.ssl_check_history USING btree (checked_at);


--
-- Name: ssl_check_history_ssl_certificate_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ssl_check_history_ssl_certificate_id_index ON public.ssl_check_history USING btree (ssl_certificate_id);


--
-- Name: status_page_incidents_status_page_id_status_started_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX status_page_incidents_status_page_id_status_started_at_index ON public.status_page_incidents USING btree (status_page_id, status, started_at);


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
-- Name: vulnerability_alerts_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vulnerability_alerts_site_id_status_index ON public.vulnerability_alerts USING btree (site_id, status);


--
-- Name: vulnerability_alerts_site_id_status_severity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vulnerability_alerts_site_id_status_severity_index ON public.vulnerability_alerts USING btree (site_id, status, severity);


--
-- Name: woocommerce_alerts_site_id_is_acknowledged_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX woocommerce_alerts_site_id_is_acknowledged_index ON public.woocommerce_alerts USING btree (site_id, is_acknowledged);


--
-- Name: wp_audit_logs_site_id_action_type_wp_username_action_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX wp_audit_logs_site_id_action_type_wp_username_action_at_index ON public.wp_audit_logs USING btree (site_id, action_type, wp_username, action_at);


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
-- Name: blocked_requests blocked_requests_ip_rule_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blocked_requests
    ADD CONSTRAINT blocked_requests_ip_rule_id_foreign FOREIGN KEY (ip_rule_id) REFERENCES public.ip_rules(id) ON DELETE SET NULL;


--
-- Name: blocked_requests blocked_requests_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blocked_requests
    ADD CONSTRAINT blocked_requests_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


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
-- Name: core_file_checks core_file_checks_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_file_checks
    ADD CONSTRAINT core_file_checks_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


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
-- Name: dns_records_cache dns_records_cache_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dns_records_cache
    ADD CONSTRAINT dns_records_cache_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: domain_check_history domain_check_history_domain_monitor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_check_history
    ADD CONSTRAINT domain_check_history_domain_monitor_id_foreign FOREIGN KEY (domain_monitor_id) REFERENCES public.domain_monitors(id) ON DELETE CASCADE;


--
-- Name: domain_monitors domain_monitors_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.domain_monitors
    ADD CONSTRAINT domain_monitors_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: email_health_checks email_health_checks_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_health_checks
    ADD CONSTRAINT email_health_checks_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: error_logs error_logs_resolved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.error_logs
    ADD CONSTRAINT error_logs_resolved_by_foreign FOREIGN KEY (resolved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: error_logs error_logs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.error_logs
    ADD CONSTRAINT error_logs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: ip_rules ip_rules_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ip_rules
    ADD CONSTRAINT ip_rules_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ip_rules ip_rules_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ip_rules
    ADD CONSTRAINT ip_rules_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: keyword_positions keyword_positions_tracked_keyword_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.keyword_positions
    ADD CONSTRAINT keyword_positions_tracked_keyword_id_foreign FOREIGN KEY (tracked_keyword_id) REFERENCES public.tracked_keywords(id) ON DELETE CASCADE;


--
-- Name: link_monitors link_monitors_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.link_monitors
    ADD CONSTRAINT link_monitors_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: link_scans link_scans_link_monitor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.link_scans
    ADD CONSTRAINT link_scans_link_monitor_id_foreign FOREIGN KEY (link_monitor_id) REFERENCES public.link_monitors(id) ON DELETE CASCADE;


--
-- Name: link_scans link_scans_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.link_scans
    ADD CONSTRAINT link_scans_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: links links_link_scan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.links
    ADD CONSTRAINT links_link_scan_id_foreign FOREIGN KEY (link_scan_id) REFERENCES public.link_scans(id) ON DELETE CASCADE;


--
-- Name: links links_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.links
    ADD CONSTRAINT links_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: maintenance_windows maintenance_windows_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_windows
    ADD CONSTRAINT maintenance_windows_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: maintenance_windows maintenance_windows_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.maintenance_windows
    ADD CONSTRAINT maintenance_windows_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


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
-- Name: resource_checks resource_checks_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.resource_checks
    ADD CONSTRAINT resource_checks_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


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
-- Name: seo_alerts seo_alerts_dismissed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_alerts
    ADD CONSTRAINT seo_alerts_dismissed_by_foreign FOREIGN KEY (dismissed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: seo_alerts seo_alerts_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_alerts
    ADD CONSTRAINT seo_alerts_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: seo_configs seo_configs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_configs
    ADD CONSTRAINT seo_configs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: seo_pinned_keywords seo_pinned_keywords_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_pinned_keywords
    ADD CONSTRAINT seo_pinned_keywords_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: seo_snapshots seo_snapshots_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_snapshots
    ADD CONSTRAINT seo_snapshots_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: seo_top_pages seo_top_pages_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_top_pages
    ADD CONSTRAINT seo_top_pages_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: seo_top_queries seo_top_queries_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_top_queries
    ADD CONSTRAINT seo_top_queries_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


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
-- Name: site_cron_jobs site_cron_jobs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_cron_jobs
    ADD CONSTRAINT site_cron_jobs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


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
-- Name: site_preset_modules site_preset_modules_site_preset_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_preset_modules
    ADD CONSTRAINT site_preset_modules_site_preset_id_foreign FOREIGN KEY (site_preset_id) REFERENCES public.site_presets(id) ON DELETE CASCADE;


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
-- Name: sites sites_applied_preset_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_applied_preset_id_foreign FOREIGN KEY (applied_preset_id) REFERENCES public.site_presets(id) ON DELETE SET NULL;


--
-- Name: sites sites_client_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_client_id_foreign FOREIGN KEY (client_id) REFERENCES public.clients(id) ON DELETE CASCADE;


--
-- Name: sites sites_site_status_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_site_status_id_foreign FOREIGN KEY (site_status_id) REFERENCES public.site_statuses(id) ON DELETE SET NULL;


--
-- Name: ssl_certificates ssl_certificates_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ssl_certificates
    ADD CONSTRAINT ssl_certificates_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: ssl_check_history ssl_check_history_ssl_certificate_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ssl_check_history
    ADD CONSTRAINT ssl_check_history_ssl_certificate_id_foreign FOREIGN KEY (ssl_certificate_id) REFERENCES public.ssl_certificates(id) ON DELETE CASCADE;


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
-- Name: woocommerce_alerts woocommerce_alerts_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.woocommerce_alerts
    ADD CONSTRAINT woocommerce_alerts_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: woocommerce_stats woocommerce_stats_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.woocommerce_stats
    ADD CONSTRAINT woocommerce_stats_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: wp_audit_logs wp_audit_logs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.wp_audit_logs
    ADD CONSTRAINT wp_audit_logs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict r5eq3YHVQRJovog408rpeD4aSNOUSVBmrcXY43k9gAchS6V7JbNhRPn1xuE6Gxh

