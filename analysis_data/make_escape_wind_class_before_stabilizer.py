from pathlib import Path
from collections import defaultdict
from datetime import date
import json

from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment
from openpyxl.utils import get_column_letter


# =========================
# 基本設定
# =========================

BASE_DIR = Path("/workspaces/BoatraceResults")
RESULT_DIR = BASE_DIR / "docs" / "v3"
DATA_DIR = BASE_DIR / "analysis_data"

START_DATE = date(2024, 1, 1)
END_DATE = date(2026, 10, 5)

OUTPUT_FILE = DATA_DIR / "boat_escape_wind_class_20240101_20261005.xlsx"


STADIUMS = {
    1: "桐生",
    2: "戸田",
    3: "江戸川",
    4: "平和島",
    5: "多摩川",
    6: "浜名湖",
    7: "蒲郡",
    8: "常滑",
    9: "津",
    10: "三国",
    11: "びわこ",
    12: "住之江",
    13: "尼崎",
    14: "鳴門",
    15: "丸亀",
    16: "児島",
    17: "宮島",
    18: "徳山",
    19: "下関",
    20: "若松",
    21: "芦屋",
    22: "福岡",
    23: "唐津",
    24: "大村",
}


WIND_DIRECTIONS = {
    1: "北", 2: "北", 3: "北東",
    4: "東", 5: "東", 6: "東",
    7: "南東",
    8: "南", 9: "南", 10: "南",
    11: "南西",
    12: "西", 13: "西", 14: "西",
    15: "北西", 16: "北",
    17: "無風",
}


TECHNIQUES = {
    1: "逃げ",
    2: "差し",
    3: "まくり",
    4: "まくり差し",
    5: "抜き",
    6: "恵まれ",
}


# =========================
# 期別級別ファイル
# =========================

GRADE_FILES = [
    (date(2024, 1, 1), date(2024, 6, 30), "fan2310.txt"),
    (date(2024, 7, 1), date(2024, 12, 31), "fan2404.txt"),
    (date(2025, 1, 1), date(2025, 6, 30), "fan2410.txt"),
    (date(2025, 7, 1), date(2025, 12, 31), "fan2504.txt"),
    (date(2026, 1, 1), date(2026, 6, 30), "fan2510.txt"),
    (date(2026, 7, 1), date(2026, 12, 31), "fan2604.txt"),
]


def load_grade_file(path):
    """BOAT RACE公式期別ファイルから 登録番号→級別 を取得"""
    grades = {}

    for raw in path.read_bytes().splitlines():
        if len(raw) < 41:
            continue

        try:
            racer_number = int(raw[0:4].decode("ascii"))
            racer_class = raw[39:41].decode("ascii")
        except (ValueError, UnicodeDecodeError):
            continue

        if racer_class in {"A1", "A2", "B1", "B2"}:
            grades[racer_number] = racer_class

    return grades


def load_all_grades():
    periods = []

    for start, end, filename in GRADE_FILES:
        path = DATA_DIR / filename

        if not path.exists():
            raise FileNotFoundError(
                f"級別ファイルがありません: {path}"
            )

        grades = load_grade_file(path)

        print(
            f"級別読込: {filename} "
            f"{start}～{end} "
            f"{len(grades):,}選手"
        )

        periods.append((start, end, grades))

    return periods


def get_grade(race_date, racer_number, grade_periods):
    for start, end, grades in grade_periods:
        if start <= race_date <= end:
            return grades.get(racer_number)

    return None


# =========================
# 風速
# =========================

def wind_speed_band(speed):
    """
    レース明細用の基本風速帯
    弱 = 0～2m
    中 = 3～4m
    強 = 5m以上
    """
    if speed is None:
        return "不明"

    try:
        speed = float(speed)
    except (TypeError, ValueError):
        return "不明"

    if speed <= 2:
        return "弱"
    elif speed <= 4:
        return "中"
    else:
        return "強"


def matches_wind_band(speed, band):
    """
    風速帯別集計用。
    中強は3m以上なので、中・強と重複して集計する。
    """
    if speed is None:
        return False

    try:
        speed = float(speed)
    except (TypeError, ValueError):
        return False

    if band == "弱":
        return 0 <= speed <= 2
    if band == "中":
        return 3 <= speed <= 4
    if band == "中強":
        return speed >= 3
    if band == "強":
        return speed >= 5

    return False


# =========================
# レース明細作成
# =========================

def collect_races(grade_periods):
    rows = []
    json_files = []

    for year in (2024, 2025, 2026):
        year_dir = RESULT_DIR / str(year)

        for path in sorted(year_dir.glob("*.json")):
            try:
                file_date = date(
                    int(path.stem[0:4]),
                    int(path.stem[4:6]),
                    int(path.stem[6:8]),
                )
            except ValueError:
                continue

            if START_DATE <= file_date <= END_DATE:
                json_files.append(path)

    print(f"対象JSON: {len(json_files):,}ファイル")

    race_count = 0
    missing_course1 = 0
    missing_grade = 0
    missing_winner_grade = 0

    actual_min_date = None
    actual_max_date = None

    for index, path in enumerate(json_files, start=1):
        data = json.loads(path.read_text(encoding="utf-8"))

        for race in data.get("results", []):
            race_count += 1

            race_date = date.fromisoformat(race["date"])

            if actual_min_date is None or race_date < actual_min_date:
                actual_min_date = race_date

            if actual_max_date is None or race_date > actual_max_date:
                actual_max_date = race_date

            boats = race.get("boats", [])

            course1 = next(
                (
                    boat
                    for boat in boats
                    if boat.get("racer_course_number") == 1
                ),
                None,
            )

            if course1 is None:
                missing_course1 += 1
                continue

            # 1コース選手の級別
            racer_number = course1.get("racer_number")

            racer_class = get_grade(
                race_date,
                racer_number,
                grade_periods,
            )

            if racer_class is None:
                missing_grade += 1
                racer_class = "不明"

            # 1着選手を探す
            winner = next(
                (
                    boat
                    for boat in boats
                    if boat.get("racer_place_number") == 1
                ),
                None,
            )

            winner_class = "不明"

            if winner is not None:
                winner_number = winner.get("racer_number")

                found_winner_class = get_grade(
                    race_date,
                    winner_number,
                    grade_periods,
                )

                if found_winner_class is not None:
                    winner_class = found_winner_class
                else:
                    missing_winner_grade += 1
            else:
                missing_winner_grade += 1

            technique_number = race.get("technique_number")

            technique = TECHNIQUES.get(
                technique_number,
                f"番号{technique_number}",
            )

            place = course1.get("racer_place_number")

            # 逃げ = 実際の1コース選手が1着、
            # かつ決まり手番号=1
            is_escape = (
                place == 1
                and technique_number == 1
            )

            wind_speed = race.get("wind_speed")

            wind_direction_number = race.get(
                "wind_direction_number"
            )

            trifecta = (
                race.get("payouts", {})
                .get("trifecta", [])
            )

            trifecta_combination = " / ".join(
                str(x.get("combination", ""))
                for x in trifecta
            )

            trifecta_amount = " / ".join(
                str(x.get("amount", ""))
                for x in trifecta
            )

            rows.append(
                {
                    "日付": race_date,
                    "場番号": race.get("stadium_number"),
                    "場": STADIUMS.get(
                        race.get("stadium_number"),
                        str(race.get("stadium_number")),
                    ),
                    "R": race.get("number"),
                    "風向番号": wind_direction_number,
                    "風向": WIND_DIRECTIONS.get(
                        wind_direction_number,
                        "不明",
                    ),
                    "風速": wind_speed,
                    "風速帯": wind_speed_band(wind_speed),
                    "1コース艇番": course1.get(
                        "racer_boat_number"
                    ),
                    "1コース選手": course1.get(
                        "racer_name"
                    ),
                    "登録番号": racer_number,
                    "級別": racer_class,
                    "ST": course1.get(
                        "racer_start_timing"
                    ),
                    "1コース着順": place,
                    "決まり手番号": technique_number,
                    "決まり手": technique,
                    "1着級別": winner_class,
                    "3連単組合せ": trifecta_combination,
                    "3連単払戻": trifecta_amount,
                    "逃げ": 1 if is_escape else 0,
                }
            )

        if index % 100 == 0:
            print(
                f"読込中: {index:,}/"
                f"{len(json_files):,} "
                f"({race_count:,}レース)"
            )

    print()
    print(f"総レース数: {race_count:,}")
    print(f"明細行数: {len(rows):,}")
    print(f"1コース不明: {missing_course1:,}")
    print(f"1コース級別不明: {missing_grade:,}")
    print(f"1着級別不明: {missing_winner_grade:,}")
    print(f"実データ開始日: {actual_min_date}")
    print(f"実データ終了日: {actual_max_date}")

    return rows


# =========================
# 既存集計
# =========================

def make_summary(rows):
    summary = defaultdict(
        lambda: {
            "レース数": 0,
            "逃げ数": 0,
            "3連単払戻合計": 0,
            "3連単件数": 0,
        }
    )

    for row in rows:
        key = (
            row["場番号"],
            row["場"],
            row["風向"],
            row["風速"],
            row["風速帯"],
            row["級別"],
        )

        summary[key]["レース数"] += 1
        summary[key]["逃げ数"] += row["逃げ"]

        payout = row["3連単払戻"]

        if payout and payout.isdigit():
            summary[key]["3連単払戻合計"] += int(payout)
            summary[key]["3連単件数"] += 1

    result = []

    for key, values in summary.items():
        (
            stadium_number,
            stadium,
            wind_direction,
            wind_speed,
            wind_band,
            racer_class,
        ) = key

        races = values["レース数"]
        escapes = values["逃げ数"]
        trifecta_count = values["3連単件数"]

        avg_trifecta = (
            values["3連単払戻合計"] / trifecta_count
            if trifecta_count
            else 0
        )

        result.append(
            {
                "場番号": stadium_number,
                "場": stadium,
                "風向": wind_direction,
                "風速": wind_speed,
                "風速帯": wind_band,
                "級別": racer_class,
                "レース数": races,
                "逃げ数": escapes,
                "逃げ率": escapes / races if races else 0,
                "平均3連単払戻": avg_trifecta,
            }
        )

    result.sort(
        key=lambda x: (
            x["場番号"]
            if x["場番号"] is not None
            else 999,
            x["風向"],
            x["風速"]
            if x["風速"] is not None
            else 999,
            x["級別"],
        )
    )

    return result


def make_class_summary(rows):
    summary = defaultdict(
        lambda: {
            "レース数": 0,
            "逃げ数": 0,
        }
    )

    for row in rows:
        key = (
            row["場番号"],
            row["場"],
            row["級別"],
        )

        summary[key]["レース数"] += 1
        summary[key]["逃げ数"] += row["逃げ"]

    result = []

    for key, values in summary.items():
        stadium_number, stadium, racer_class = key

        races = values["レース数"]
        escapes = values["逃げ数"]

        result.append(
            {
                "場番号": stadium_number,
                "場": stadium,
                "級別": racer_class,
                "レース数": races,
                "逃げ数": escapes,
                "逃げ率": escapes / races if races else 0,
            }
        )

    result.sort(
        key=lambda x: (
            x["場番号"]
            if x["場番号"] is not None
            else 999,
            x["級別"],
        )
    )

    return result


# =========================
# 風速帯別集計
# =========================

def make_wind_band_summary(rows):
    """
    場 × 1コース選手級別 × 風速帯
    セル表示：
    逃げ率(対象レース数)
    例 62.0%(100)
    """

    classes = ["A1", "A2", "B1", "B2"]
    bands = ["弱", "中", "中強", "強"]

    summary = {}

    for stadium_number in range(1, 25):
        for racer_class in classes:
            for band in bands:
                summary[
                    (stadium_number, racer_class, band)
                ] = {
                    "レース数": 0,
                    "逃げ数": 0,
                }

    for row in rows:
        stadium_number = row["場番号"]
        racer_class = row["級別"]
        speed = row["風速"]

        if racer_class not in classes:
            continue

        for band in bands:
            if matches_wind_band(speed, band):
                key = (
                    stadium_number,
                    racer_class,
                    band,
                )

                if key not in summary:
                    continue

                summary[key]["レース数"] += 1
                summary[key]["逃げ数"] += row["逃げ"]

    return summary


# =========================
# Excel書式
# =========================

def style_sheet(ws, freeze="A2"):
    ws.freeze_panes = freeze

    if ws.max_row >= 1:
        ws.auto_filter.ref = ws.dimensions

    header_fill = PatternFill(
        fill_type="solid",
        fgColor="1F4E78",
    )

    header_font = Font(
        color="FFFFFF",
        bold=True,
    )

    for cell in ws[1]:
        cell.fill = header_fill
        cell.font = header_font

    ws.row_dimensions[1].height = 24


def center_all_cells(ws):
    """シート内の全セルを上下左右中央揃え"""
    for row in ws.iter_rows():
        for cell in row:
            cell.alignment = Alignment(
                horizontal="center",
                vertical="center",
            )


def set_widths(ws, widths):
    for column, width in widths.items():
        ws.column_dimensions[column].width = width


# =========================
# Excel作成
# =========================

def create_workbook(
    rows,
    summary_rows,
    class_summary_rows,
    wind_band_summary,
):
    print()
    print("Excel作成開始...")

    wb = Workbook()

    # =========================
    # 1. レース明細
    # =========================

    ws = wb.active
    ws.title = "レース明細"

    detail_headers = [
        "日付",
        "場番号",
        "場",
        "R",
        "風向",
        "風速",
        "風速帯",
        "1コース艇番",
        "1コース選手",
        "登録番号",
        "級別",
        "ST",
        "1コース着順",
        "決まり手番号",
        "決まり手",
        "1着級別",
        "3連単組合せ",
        "3連単払戻",
        "逃げ",
    ]

    ws.append(detail_headers)

    for row in rows:
        values = []

        for header in detail_headers:
            value = row[header]

            # 風速だけ「m」を付けて表示
            if header == "風速" and value is not None:
                value = f"{value}m"

            values.append(value)

        ws.append(values)

    for cell in ws["A"][1:]:
        cell.number_format = "yyyy/mm/dd"

    # ST
    for cell in ws["L"][1:]:
        cell.number_format = "0.00"

    style_sheet(ws)

    set_widths(
        ws,
        {
            "A": 12,
            "B": 8,
            "C": 10,
            "D": 6,
            "E": 10,
            "F": 8,
            "G": 10,
            "H": 12,
            "I": 18,
            "J": 12,
            "K": 8,
            "L": 8,
            "M": 12,
            "N": 12,
            "O": 14,
            "P": 10,
            "Q": 16,
            "R": 14,
            "S": 8,
        },
    )

    print(f"  レース明細: {len(rows):,}行")

    # =========================
    # 2. 風向風速級別集計
    # =========================

    ws2 = wb.create_sheet("風向風速級別集計")

    summary_headers = [
        "場番号",
        "場",
        "風向",
        "風速",
        "風速帯",
        "級別",
        "レース数",
        "逃げ数",
        "逃げ率",
        "平均3連単払戻",
    ]

    ws2.append(summary_headers)

    for row in summary_rows:
        values = [
            row[header]
            for header in summary_headers
        ]

        # 風速列にmを付ける
        if values[3] is not None:
            values[3] = f"{values[3]}m"

        ws2.append(values)

    for cell in ws2["J"][1:]:
        cell.number_format = '#,##0"円"'

    for cell in ws2["I"][1:]:
        cell.number_format = "0.0%"

    style_sheet(ws2)

    set_widths(
        ws2,
        {
            "A": 8,
            "B": 10,
            "C": 12,
            "D": 8,
            "E": 10,
            "F": 8,
            "G": 12,
            "H": 10,
            "I": 12,
            "J": 16,
        },
    )

    print(
        f"  風向風速級別集計: "
        f"{len(summary_rows):,}行"
    )

    # =========================
    # 3. 場級別集計
    # =========================

    ws3 = wb.create_sheet("場級別集計")

    class_headers = [
        "場番号",
        "場",
        "級別",
        "レース数",
        "逃げ数",
        "逃げ率",
    ]

    ws3.append(class_headers)

    for row in class_summary_rows:
        ws3.append(
            [
                row[header]
                for header in class_headers
            ]
        )

    for cell in ws3["F"][1:]:
        cell.number_format = "0.0%"

    style_sheet(ws3)

    set_widths(
        ws3,
        {
            "A": 8,
            "B": 10,
            "C": 8,
            "D": 12,
            "E": 10,
            "F": 12,
        },
    )

    print(
        f"  場級別集計: "
        f"{len(class_summary_rows):,}行"
    )

    # =========================
    # 4. 風速帯別集計
    # =========================

    ws4 = wb.create_sheet("風速帯別集計")

    classes = ["A1", "A2", "B1", "B2"]
    bands = ["弱", "中", "中強", "強"]

    headers = ["場"]

    for racer_class in classes:
        for band in bands:
            headers.append(
                f"{racer_class}({band})"
            )

    ws4.append(headers)

    for stadium_number in range(1, 25):
        values = [
            STADIUMS[stadium_number]
        ]

        for racer_class in classes:
            for band in bands:
                data = wind_band_summary[
                    (
                        stadium_number,
                        racer_class,
                        band,
                    )
                ]

                races = data["レース数"]
                escapes = data["逃げ数"]

                if races:
                    rate = escapes / races * 100
                    display = (
                        f"{rate:.1f}%({races})"
                    )
                else:
                    display = "0.0%(0)"

                values.append(display)

        ws4.append(values)

    style_sheet(ws4)

    ws4.column_dimensions["A"].width = 12

    for col in range(2, 18):
        ws4.column_dimensions[
            get_column_letter(col)
        ].width = 14

    print("  風速帯別集計: 24場 × 16条件")

    # =========================
    # 5. 説明
    # =========================

    ws5 = wb.create_sheet("説明")

    actual_dates = [
        row["日付"]
        for row in rows
        if row.get("日付") is not None
    ]

    actual_start = (
        min(actual_dates)
        if actual_dates
        else None
    )

    actual_end = (
        max(actual_dates)
        if actual_dates
        else None
    )

    unknown_grade = sum(
        1
        for row in rows
        if row["級別"] == "不明"
    )

    unknown_winner_grade = sum(
        1
        for row in rows
        if row["1着級別"] == "不明"
    )

    escape_count = sum(
        row["逃げ"]
        for row in rows
    )

    info_rows = [
        ["項目", "内容"],
        ["指定開始日", START_DATE],
        ["指定終了日", END_DATE],
        ["実データ開始日", actual_start],
        ["実データ終了日", actual_end],
        ["分析対象レース数", len(rows)],
        ["逃げ数", escape_count],
        [
            "全体逃げ率",
            escape_count / len(rows)
            if rows
            else 0,
        ],
        ["1コース級別不明件数", unknown_grade],
        ["1着級別不明件数", unknown_winner_grade],
        [
            "1コースの定義",
            "racer_course_number = 1 の実際の1コース進入選手",
        ],
        [
            "逃げの定義",
            "実際の1コース選手が1着、かつ決まり手番号=1（逃げ）",
        ],
        [
            "級別",
            "各レース開催日時点の適用期別ファイルから取得",
        ],
        [
            "風速帯：弱",
            "0m～2m",
        ],
        [
            "風速帯：中",
            "3m～4m",
        ],
        [
            "風速帯：中強",
            "3m以上（中・強と重複して集計）",
        ],
        [
            "風速帯：強",
            "5m以上",
        ],
        [
            "風速帯別集計",
            "1着選手の級別 × 風速帯ごとに、逃げ率(対象レース数)を表示",
        ],
        [
            "風速帯別集計の例",
            "62.0%(100) = 対象100レース中、逃げ62レース",
        ],
        [
            "既存集計単位",
            "場 × 風向 × 風速 × 1コース選手級別",
        ],
    ]

    for info in info_rows:
        ws5.append(info)

    for row_number in range(2, 6):
        ws5.cell(
            row=row_number,
            column=2,
        ).number_format = "yyyy/mm/dd"

    ws5["B8"].number_format = "0.0%"

    style_sheet(ws5)

    ws5.column_dimensions["A"].width = 28
    ws5.column_dimensions["B"].width = 75

    # =========================
    # 全シート中央揃え
    # =========================

    for sheet in wb.worksheets:
        center_all_cells(sheet)

    # =========================
    # 保存
    # =========================

    wb.save(OUTPUT_FILE)

    print()
    print("=" * 60)
    print("Excel完成")
    print(f"保存先: {OUTPUT_FILE}")
    print(
        f"ファイルサイズ: "
        f"{OUTPUT_FILE.stat().st_size:,} bytes"
    )
    print("=" * 60)


def main():
    print("=" * 60)
    print("BOAT RACE 1コース逃げ率分析")
    print(
        f"指定期間: "
        f"{START_DATE} ～ {END_DATE}"
    )
    print("=" * 60)
    print()

    grade_periods = load_all_grades()

    print()
    print("レースデータ読込開始...")

    rows = collect_races(grade_periods)

    if not rows:
        raise RuntimeError(
            "対象レースが1件も取得できませんでした。"
        )

    print()
    print("集計開始...")

    summary_rows = make_summary(rows)

    class_summary_rows = make_class_summary(
        rows
    )

    wind_band_summary = make_wind_band_summary(
        rows
    )

    create_workbook(
        rows,
        summary_rows,
        class_summary_rows,
        wind_band_summary,
    )


if __name__ == "__main__":
    main()