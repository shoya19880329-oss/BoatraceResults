from pathlib import Path
from collections import defaultdict
from datetime import date
import json

from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment
from openpyxl.utils import get_column_letter
from openpyxl.worksheet.table import Table, TableStyleInfo


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


# BOAT RACE結果データの決まり手番号
WIND_DIRECTIONS = {1:"北",2:"北",3:"北東",4:"東",5:"東",6:"東",7:"南東",8:"南",9:"南",10:"南",11:"南西",12:"西",13:"西",14:"西",15:"北西",16:"北",17:"無風"}

TECHNIQUES = {
    1: "逃げ",
    2: "差し",
    3: "まくり",
    4: "まくり差し",
    5: "抜き",
    6: "恵まれ",
}


# 期別級別ファイル
# 前期：1/1～6/30
# 後期：7/1～12/31
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
            raise FileNotFoundError(f"級別ファイルがありません: {path}")

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


def wind_speed_band(speed):
    """風速を1m単位で集計。6m以上はまとめる"""
    if speed is None:
        return "不明"

    try:
        speed = float(speed)
    except (TypeError, ValueError):
        return "不明"

    if speed >= 6:
        return "6m以上"

    return f"{int(speed)}m"


def collect_races(grade_periods):
    """対象期間の全レースから1コース選手の明細を作成"""
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

            course1 = next(
                (
                    boat
                    for boat in race.get("boats", [])
                    if boat.get("racer_course_number") == 1
                ),
                None,
            )

            if course1 is None:
                missing_course1 += 1
                continue

            racer_number = course1.get("racer_number")
            racer_class = get_grade(
                race_date,
                racer_number,
                grade_periods,
            )

            if racer_class is None:
                missing_grade += 1
                racer_class = "不明"

            technique_number = race.get("technique_number")
            technique = TECHNIQUES.get(
                technique_number,
                f"番号{technique_number}",
            )

            place = course1.get("racer_place_number")

            # 「逃げ」は1コース選手が1着かつ決まり手が逃げ
            is_escape = (
                place == 1
                and technique_number == 1
            )

            wind_speed = race.get("wind_speed")
            wind_direction_number = race.get(
                "wind_direction_number"
            )

            trifecta = race.get("payouts", {}).get("trifecta", [])
            trifecta_combination = " / ".join(str(x.get("combination", "")) for x in trifecta)
            trifecta_amount = " / ".join(str(x.get("amount", "")) for x in trifecta)
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
                "風向": WIND_DIRECTIONS.get(wind_direction_number, "不明"),
                    "風速": wind_speed,
                    "風速帯": wind_speed_band(wind_speed),
                    "1コース艇番": course1.get(
                        "racer_boat_number"
                    ),
                    "1コース選手": course1.get("racer_name"),
                    "登録番号": racer_number,
                    "級別": racer_class,
                    "ST": course1.get("racer_start_timing"),
                    "1コース着順": place,
                    "決まり手番号": technique_number,
                    "決まり手": technique,
                    "逃げ": 1 if is_escape else 0,
                    "3連単組合せ": trifecta_combination,
                    "3連単払戻": trifecta_amount,
                }
            )

        if index % 100 == 0:
            print(
                f"読込中: {index:,}/{len(json_files):,} "
                f"({race_count:,}レース)"
            )

    print()
    print(f"総レース数: {race_count:,}")
    print(f"明細行数: {len(rows):,}")
    print(f"1コース不明: {missing_course1:,}")
    print(f"級別不明: {missing_grade:,}")
    print(f"実データ開始日: {actual_min_date}")
    print(f"実データ終了日: {actual_max_date}")

    return rows
def make_summary(rows):
    """場×風向番号×風速×級別で逃げ率を集計"""
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
        if row["3連単払戻"] and row["3連単払戻"].isdigit():
            summary[key]["3連単払戻合計"] += int(row["3連単払戻"])
            summary[key]["3連単件数"] += 1
        summary[key]["逃げ数"] += row["逃げ"]

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
        trifecta_count = values["3連単件数"]
        avg_trifecta = values["3連単払戻合計"] / trifecta_count if trifecta_count else 0

        races = values["レース数"]
        escapes = values["逃げ数"]

        escape_rate = escapes / races if races else 0

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
                "逃げ率": escape_rate,
                "平均3連単払戻": avg_trifecta,
            }
        )

    result.sort(
        key=lambda x: (
            x["場番号"] if x["場番号"] is not None else 999,
            {"北":1,"北東":2,"東":3,"南東":4,"南":5,"南西":6,"西":7,"北西":8,"無風":9,"不明":10}.get(x["風向"],99),
            x["風速"]
            if x["風速"] is not None
            else 999,
            x["級別"],
        )
    )

    print(f"集計行数: {len(result):,}")

    return result


def make_class_summary(rows):
    """場×級別の基本逃げ率も作成"""
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
            x["場番号"] if x["場番号"] is not None else 999,
            x["級別"],
        )
    )

    return result
def style_sheet(ws, freeze="A2"):
    """共通のExcel書式"""
    ws.freeze_panes = freeze
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
        cell.alignment = Alignment(
            horizontal="center",
            vertical="center",
        )

    ws.row_dimensions[1].height = 24


def set_widths(ws, widths):
    for column, width in widths.items():
        ws.column_dimensions[column].width = width


def add_excel_table(ws, name):
    if ws.max_row < 2:
        return

    table = Table(
        displayName=name,
        ref=f"A1:{get_column_letter(ws.max_column)}{ws.max_row}",
    )

    style = TableStyleInfo(
        name="TableStyleMedium2",
        showFirstColumn=False,
        showLastColumn=False,
        showRowStripes=True,
        showColumnStripes=False,
    )

    table.tableStyleInfo = style
    ws.add_table(table)


def create_workbook(rows, summary_rows, class_summary_rows):
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
          "3連単組合せ",
          "3連単払戻",
        "逃げ",
    ]

    ws.append(detail_headers)

    for row in rows:
        ws.append(
            [row[header] for header in detail_headers]
        )

    for cell in ws["A"][1:]:
        cell.number_format = "yyyy/mm/dd"

    for cell in ws["L"][1:]:
        cell.number_format = "0.00"

    style_sheet(ws)
#    add_excel_table(ws, "RaceDetailTable")

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
            "P": 8,
        },
    )

    print(f"  レース明細: {len(rows):,}行")

    # =========================
    # 2. 風向・風速・級別集計
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
        ws2.append(
            [row[header] for header in summary_headers]
        )

    for cell in ws2["J"][1:]:
        cell.number_format = '#,##0"円"'

    for cell in ws2["I"][1:]:
        cell.number_format = "0.0%"

    style_sheet(ws2)
#    add_excel_table(ws2, "WindClassSummaryTable")

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
        },
    )

    print(
        f"  風向風速級別集計: "
        f"{len(summary_rows):,}行"
    )

    # =========================
    # 3. 場・級別集計
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
            [row[header] for header in class_headers]
        )

    for cell in ws3["F"][1:]:
        cell.number_format = "0.0%"

    style_sheet(ws3)
#    add_excel_table(ws3, "ClassSummaryTable")

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
    )    # =========================
    # 4. 説明・診断
    # =========================
    ws4 = wb.create_sheet("説明")

    actual_dates = [
        row["日付"]
        for row in rows
        if row.get("日付") is not None
    ]

    actual_start = min(actual_dates) if actual_dates else None
    actual_end = max(actual_dates) if actual_dates else None

    unknown_grade = sum(
        1 for row in rows if row["級別"] == "不明"
    )

    escape_count = sum(row["逃げ"] for row in rows)

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
            escape_count / len(rows) if rows else 0,
        ],
        ["級別不明件数", unknown_grade],
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
            "集計単位",
            "場 × 風向番号 × 風速 × 級別",
        ],
    ]

    for info in info_rows:
        ws4.append(info)

    for row_number in range(2, 6):
        ws4.cell(row=row_number, column=2).number_format = (
            "yyyy/mm/dd"
        )

    ws4["B8"].number_format = "0.0%"

    header_fill = PatternFill(
        fill_type="solid",
        fgColor="1F4E78",
    )
    header_font = Font(
        color="FFFFFF",
        bold=True,
    )

    for cell in ws4[1]:
        cell.fill = header_fill
        cell.font = header_font

    ws4.column_dimensions["A"].width = 24
    ws4.column_dimensions["B"].width = 70
    ws4.freeze_panes = "A2"

    # =========================
    # 保存
    # =========================
    wb.save(OUTPUT_FILE)

    print()
    print("=" * 60)
    print("Excel完成")
    print(f"保存先: {OUTPUT_FILE}")
    print(f"ファイルサイズ: {OUTPUT_FILE.stat().st_size:,} bytes")
    print("=" * 60)


def main():
    print("=" * 60)
    print("BOAT RACE 1コース逃げ率分析")
    print(f"指定期間: {START_DATE} ～ {END_DATE}")
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
    class_summary_rows = make_class_summary(rows)

    create_workbook(
        rows,
        summary_rows,
        class_summary_rows,
    )


if __name__ == "__main__":
    main()
    