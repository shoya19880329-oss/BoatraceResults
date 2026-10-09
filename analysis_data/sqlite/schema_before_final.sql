PRAGMA foreign_keys = ON;

-- レース本体
CREATE TABLE IF NOT EXISTS races (
    race_id INTEGER PRIMARY KEY,
    race_date TEXT NOT NULL,
    stadium_number INTEGER NOT NULL,
    race_number INTEGER NOT NULL,

    closed_at TEXT,
    day_label TEXT,
    grade_label TEXT,
    grade_number INTEGER,
    title TEXT,
    subtitle TEXT,
    distance INTEGER,

    result_wind_speed REAL,
    result_wind_direction_number INTEGER,
    result_wave_height REAL,
    result_weather_number INTEGER,
    result_air_temperature REAL,
    result_water_temperature REAL,

    preview_wind_speed REAL,
    preview_wind_direction_number INTEGER,
    preview_wave_height REAL,
    preview_weather_number INTEGER,
    preview_air_temperature REAL,
    preview_water_temperature REAL,

    technique_number INTEGER,
    stabilizer_used INTEGER,

    remarks TEXT,

    UNIQUE (race_date, stadium_number, race_number)
);

-- 6艇：出走表＋本番結果
CREATE TABLE IF NOT EXISTS race_entries (
    race_id INTEGER NOT NULL,
    boat_number INTEGER NOT NULL,

    racer_number INTEGER,
    racer_name TEXT,
    racer_class_number INTEGER,
    racer_branch_number INTEGER,
    racer_birthplace_number INTEGER,
    racer_age INTEGER,

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

    actual_course_number INTEGER,
    actual_start_timing REAL,
    place_number INTEGER,

    race_time_text TEXT,
    race_time_seconds REAL,

    PRIMARY KEY (race_id, boat_number),
    FOREIGN KEY (race_id) REFERENCES races(race_id) ON DELETE CASCADE
);

-- 直前情報・展示
CREATE TABLE IF NOT EXISTS race_previews (
    race_id INTEGER NOT NULL,
    boat_number INTEGER NOT NULL,

    exhibition_course_number INTEGER,
    exhibition_start_timing REAL,

    weight REAL,
    weight_adjustment REAL,
    exhibition_time REAL,
    tilt_adjustment REAL,

    propeller_changed INTEGER,

    PRIMARY KEY (race_id, boat_number),
    FOREIGN KEY (race_id) REFERENCES races(race_id) ON DELETE CASCADE
);

-- 部品交換
CREATE TABLE IF NOT EXISTS parts_replacements (
    replacement_id INTEGER PRIMARY KEY,
    race_id INTEGER NOT NULL,
    boat_number INTEGER NOT NULL,
    part_type TEXT NOT NULL,
    quantity INTEGER,

    FOREIGN KEY (race_id) REFERENCES races(race_id) ON DELETE CASCADE
);

-- 払戻
CREATE TABLE IF NOT EXISTS payouts (
    payout_id INTEGER PRIMARY KEY,
    race_id INTEGER NOT NULL,
    bet_type TEXT NOT NULL,
    combination TEXT,
    amount INTEGER,
    popularity INTEGER,

    FOREIGN KEY (race_id) REFERENCES races(race_id) ON DELETE CASCADE
);

-- 返還
CREATE TABLE IF NOT EXISTS refunds (
    refund_id INTEGER PRIMARY KEY,
    race_id INTEGER NOT NULL,
    boat_number INTEGER,
    refund_text TEXT,

    FOREIGN KEY (race_id) REFERENCES races(race_id) ON DELETE CASCADE
);

-- 選手マスター
CREATE TABLE IF NOT EXISTS racers (
    racer_number INTEGER PRIMARY KEY,
    racer_name TEXT,
    sex TEXT,
    current_status TEXT,
    first_seen_date TEXT,
    last_seen_date TEXT
);

-- 登録・登録消除・再登録などの履歴
CREATE TABLE IF NOT EXISTS racer_registration_history (
    history_id INTEGER PRIMARY KEY,
    racer_number INTEGER NOT NULL,
    event_date TEXT,
    event_type TEXT NOT NULL,
    details TEXT,
    source TEXT,

    FOREIGN KEY (racer_number) REFERENCES racers(racer_number)
);

-- 検索高速化
CREATE INDEX IF NOT EXISTS idx_races_date
    ON races(race_date);

CREATE INDEX IF NOT EXISTS idx_races_stadium_date
    ON races(stadium_number, race_date);

CREATE INDEX IF NOT EXISTS idx_entries_racer
    ON race_entries(racer_number);

CREATE INDEX IF NOT EXISTS idx_entries_course
    ON race_entries(actual_course_number);

CREATE INDEX IF NOT EXISTS idx_entries_class
    ON race_entries(racer_class_number);

CREATE INDEX IF NOT EXISTS idx_previews_course
    ON race_previews(exhibition_course_number);

CREATE INDEX IF NOT EXISTS idx_parts_race_boat
    ON parts_replacements(race_id, boat_number);

CREATE INDEX IF NOT EXISTS idx_payouts_race
    ON payouts(race_id);

CREATE INDEX IF NOT EXISTS idx_registration_racer
    ON racer_registration_history(racer_number, event_date);
