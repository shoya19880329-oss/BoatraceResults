PRAGMA foreign_keys = ON;

-- ============================================================
-- レース本体
-- 1レースにつき1行
-- ============================================================
CREATE TABLE races (
    race_id INTEGER PRIMARY KEY,

    race_date TEXT NOT NULL,
    stadium_number INTEGER NOT NULL,
    race_number INTEGER NOT NULL,

    -- 出走表
    closed_at TEXT,
    day_label TEXT,
    grade_label TEXT,
    grade_number INTEGER,
    title TEXT,
    subtitle TEXT,
    distance INTEGER,

    -- レース状態
    race_status TEXT,

    -- 本番時の気象
    result_wind_speed REAL,
    result_wind_direction_number INTEGER,
    result_wave_height REAL,
    result_weather_number INTEGER,
    result_air_temperature REAL,
    result_water_temperature REAL,

    -- 展示時の気象
    preview_wind_speed REAL,
    preview_wind_direction_number INTEGER,
    preview_wave_height REAL,
    preview_weather_number INTEGER,
    preview_air_temperature REAL,
    preview_water_temperature REAL,

    -- 結果
    technique_number INTEGER,

    -- 安定板
    stabilizer_used INTEGER
        CHECK (
            stabilizer_used IS NULL
            OR stabilizer_used IN (0,1)
        ),

    -- 備考
    remarks TEXT,

    -- データ管理
    program_fetched_at TEXT,
    preview_fetched_at TEXT,
    result_fetched_at TEXT,
    extra_fetched_at TEXT,

    program_fetch_status TEXT,
    preview_fetch_status TEXT,
    result_fetch_status TEXT,
    extra_fetch_status TEXT,

    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE (race_date, stadium_number, race_number),

    CHECK (stadium_number BETWEEN 1 AND 24),
    CHECK (race_number BETWEEN 1 AND 12)
);


-- ============================================================
-- 出走艇
-- 出走表のスナップショット ＋ 本番結果
-- ============================================================
CREATE TABLE race_entries (
    race_id INTEGER NOT NULL,
    boat_number INTEGER NOT NULL,

    -- 選手
    racer_number INTEGER,
    racer_name TEXT,
    racer_class_number INTEGER,
    racer_branch_number INTEGER,
    racer_birthplace_number INTEGER,
    racer_age INTEGER,

    -- 出走表時点
    program_weight REAL,

    flying_count INTEGER,
    late_count INTEGER,
    average_start_timing REAL,

    national_win_rate REAL,
    national_top_2_percent REAL,
    national_top_3_percent REAL,

    local_win_rate REAL,
    local_top_2_percent REAL,
    local_top_3_percent REAL,

    motor_number INTEGER,
    motor_top_2_percent REAL,
    motor_top_3_percent REAL,

    assigned_boat_number INTEGER,
    assigned_boat_top_2_percent REAL,
    assigned_boat_top_3_percent REAL,

    -- 本番
    actual_course_number INTEGER,
    actual_start_timing REAL,
    place_number INTEGER,

    -- レースタイム
    race_time_text TEXT,
    race_time_seconds REAL,

    PRIMARY KEY (race_id, boat_number),

    FOREIGN KEY (race_id)
        REFERENCES races(race_id)
        ON DELETE CASCADE,

    CHECK (boat_number BETWEEN 1 AND 6),

    CHECK (
        actual_course_number IS NULL
        OR actual_course_number BETWEEN 1 AND 6
    )
);


-- ============================================================
-- 展示・直前情報
-- ============================================================
CREATE TABLE race_previews (
    race_id INTEGER NOT NULL,
    boat_number INTEGER NOT NULL,

    exhibition_course_number INTEGER,
    exhibition_start_timing REAL,

    weight REAL,
    weight_adjustment REAL,
    exhibition_time REAL,
    tilt_adjustment REAL,

    -- 「新」表示の場合1
    propeller_changed INTEGER
        CHECK (
            propeller_changed IS NULL
            OR propeller_changed IN (0,1)
        ),

    PRIMARY KEY (race_id, boat_number),

    FOREIGN KEY (race_id)
        REFERENCES races(race_id)
        ON DELETE CASCADE,

    CHECK (boat_number BETWEEN 1 AND 6),

    CHECK (
        exhibition_course_number IS NULL
        OR exhibition_course_number BETWEEN 1 AND 6
    )
);


-- ============================================================
-- 部品交換
-- 1艇で複数種類交換された場合も別行で保存
-- ============================================================
CREATE TABLE parts_replacements (
    replacement_id INTEGER PRIMARY KEY,

    race_id INTEGER NOT NULL,
    boat_number INTEGER NOT NULL,

    part_type TEXT NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1,

    FOREIGN KEY (race_id)
        REFERENCES races(race_id)
        ON DELETE CASCADE,

    CHECK (boat_number BETWEEN 1 AND 6),
    CHECK (quantity >= 1),

    UNIQUE (
        race_id,
        boat_number,
        part_type
    )
);

-- ============================================================
-- 払戻
-- 同じ勝式でも複数組あるため1組1行
-- ============================================================
CREATE TABLE payouts (
    payout_id INTEGER PRIMARY KEY,

    race_id INTEGER NOT NULL,
    bet_type TEXT NOT NULL,
    combination TEXT,
    amount INTEGER,
    popularity INTEGER,

    FOREIGN KEY (race_id)
        REFERENCES races(race_id)
        ON DELETE CASCADE,

    CHECK (amount IS NULL OR amount >= 0),
    CHECK (popularity IS NULL OR popularity >= 1),

    UNIQUE (
        race_id,
        bet_type,
        combination,
        amount
    )
);


-- ============================================================
-- 返還
-- 艇番を特定できる場合はboat_numberにも保存
-- 公式表示原文もrefund_textとして残す
-- ============================================================
CREATE TABLE refunds (
    refund_id INTEGER PRIMARY KEY,

    race_id INTEGER NOT NULL,
    boat_number INTEGER,
    refund_text TEXT,

    FOREIGN KEY (race_id)
        REFERENCES races(race_id)
        ON DELETE CASCADE,

    CHECK (
        boat_number IS NULL
        OR boat_number BETWEEN 1 AND 6
    ),

    UNIQUE (
        race_id,
        boat_number,
        refund_text
    )
);


-- ============================================================
-- 選手マスター
-- 現在情報と、DB内で確認できた期間
-- ============================================================
CREATE TABLE racers (
    racer_number INTEGER PRIMARY KEY,

    racer_name TEXT,
    sex TEXT,
    current_status TEXT,

    first_seen_date TEXT,
    last_seen_date TEXT,

    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);


-- ============================================================
-- 選手の登録履歴
-- 登録・登録消除・再登録など
-- ============================================================
CREATE TABLE racer_registration_history (
    history_id INTEGER PRIMARY KEY,

    racer_number INTEGER NOT NULL,
    event_date TEXT,
    event_type TEXT NOT NULL,
    details TEXT,
    source TEXT,

    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (racer_number)
        REFERENCES racers(racer_number)
        ON DELETE CASCADE,

    UNIQUE (
        racer_number,
        event_date,
        event_type
    )
);


-- ============================================================
-- データ取得履歴
-- 日次更新・再取得・エラー調査用
-- ============================================================
CREATE TABLE ingestion_log (
    ingestion_id INTEGER PRIMARY KEY,

    race_date TEXT,
    stadium_number INTEGER,
    race_number INTEGER,

    source_type TEXT NOT NULL,
    status TEXT NOT NULL,

    started_at TEXT,
    finished_at TEXT,

    http_status INTEGER,
    error_message TEXT,

    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CHECK (
        stadium_number IS NULL
        OR stadium_number BETWEEN 1 AND 24
    ),

    CHECK (
        race_number IS NULL
        OR race_number BETWEEN 1 AND 12
    )
);


-- ============================================================
-- インデックス
-- ============================================================

CREATE INDEX idx_races_date
    ON races(race_date);

CREATE INDEX idx_races_stadium_date
    ON races(stadium_number, race_date);

CREATE INDEX idx_races_status
    ON races(race_status);

CREATE INDEX idx_entries_racer
    ON race_entries(racer_number);

CREATE INDEX idx_entries_course
    ON race_entries(actual_course_number);

CREATE INDEX idx_entries_class
    ON race_entries(racer_class_number);

CREATE INDEX idx_entries_racer_date_lookup
    ON race_entries(racer_number, race_id);

CREATE INDEX idx_previews_course
    ON race_previews(exhibition_course_number);

CREATE INDEX idx_parts_race_boat
    ON parts_replacements(race_id, boat_number);

CREATE INDEX idx_payouts_race
    ON payouts(race_id);

CREATE INDEX idx_refunds_race
    ON refunds(race_id);

CREATE INDEX idx_registration_racer
    ON racer_registration_history(racer_number, event_date);

CREATE INDEX idx_ingestion_date
    ON ingestion_log(race_date);

CREATE INDEX idx_ingestion_status
    ON ingestion_log(status);

CREATE INDEX idx_ingestion_race
    ON ingestion_log(
        race_date,
        stadium_number,
        race_number
    );
