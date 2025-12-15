<?php
require_once "inc/session.php";
require_once "inc/db.php";
require_once "inc/movie_api_combined.php";

// ⭐ 버전 B 기능: 재개봉 영화 맨 뒤로 정렬 (인기순 기준)
$now_playing_movies = get_movies_from_db('now_playing');
$upcoming_movies_raw = get_movies_from_db('upcoming');

$oneYearAgo = date('Y-m-d', strtotime('-1 year'));
usort($now_playing_movies, function ($a, $b) use ($oneYearAgo) {
    $isOldA = !empty($a['release_date']) && $a['release_date'] < $oneYearAgo;
    $isOldB = !empty($b['release_date']) && $b['release_date'] < $oneYearAgo;

    if ($isOldA && !$isOldB) return 1;
    if ($isOldB && !$isOldA) return -1;

    return ($b['audience_count'] ?? 0) - ($a['audience_count'] ?? 0);
});

// ⭐ 중복 제거
$movie_ids = [];
foreach ($now_playing_movies as $movie) {
    $movie_ids[] = $movie['movie_id'];
}

$upcoming_movies = [];
foreach ($upcoming_movies_raw as $movie) {
    if (!in_array($movie['movie_id'], $movie_ids)) {
        $upcoming_movies[] = $movie;
    }
}

// DB에서 이벤트 목록 가져오기
$con = mysqli_connect("localhost", "root", "", "moviedb");

$create_table = "CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    cinema_type VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    link_url TEXT,
    main_image VARCHAR(255),
    detail_image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($con, $create_table);

$events_sql = "SELECT * FROM events ORDER BY created_at DESC LIMIT 3";
$events_result = mysqli_query($con, $events_sql);
$events = [];
if ($events_result) {
    while ($row = mysqli_fetch_assoc($events_result)) {
        $events[] = $row;
    }
}
mysqli_close($con);

// ⭐ 예매율 계산을 위한 전체 관객수 합계
$total_audience = 0;
foreach ($now_playing_movies as $movie) {
    $total_audience += ($movie['audience_count'] ?? 0);
}
// 0으로 나누기 방지
if ($total_audience == 0) $total_audience = 1;

// 예매율 계산 함수
function getBookingRate($audience_count, $total_audience)
{
    $rate = ($audience_count / $total_audience) * 100;
    return number_format($rate, 1);
}

// 인기순 (평점 기준)
$slider_movies = [];
foreach ($now_playing_movies as $movie) {
    if (!empty($movie['poster_image']) && $movie['poster_image'] != 'images/default_poster.jpg') {
        $slider_movies[] = $movie;
        if (count($slider_movies) >= 12) break;
    }
}

// 최신순 (개봉일 기준)
$slider_movies_latest = [];
$temp_movies = $now_playing_movies;
usort($temp_movies, function ($a, $b) {
    return strtotime($b['release_date']) - strtotime($a['release_date']);
});
foreach ($temp_movies as $movie) {
    if (!empty($movie['poster_image']) && $movie['poster_image'] != 'images/default_poster.jpg') {
        $slider_movies_latest[] = $movie;
        if (count($slider_movies_latest) >= 12) break;
    }
}

function getKoreanDate($date_string)
{
    if (empty($date_string)) return '';
    $korean_days = array('일', '월', '화', '수', '목', '금', '토');
    $day_index = date('w', strtotime($date_string));
    return date('Y.m.d', strtotime($date_string)) . '(' . $korean_days[$day_index] . ')';
}
?>
<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>한국 영화 커뮤니티 - Cinepals</title>
    <link rel="stylesheet" href="css/style.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        /* ========================================
           메인페이지 전용 스타일 (버전 A 디자인)
           ======================================== */

        /* 메인 슬라이더 - CGV 스타일 */
        .page-index .main-slider {
            position: relative;
            width: 100%;
            min-height: 630px;
            padding: 25px 0 30px;
            overflow: hidden;
            margin-bottom: 48px;
        }

        .page-index .slider-wrapper {
            position: relative;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 80px;
        }

        .page-index .slider-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .page-index .slider-header h2 {
            font-size: 28px;
            font-weight: 700;
        }

        .page-index .slider-tabs {
            display: flex;
            gap: 10px;
        }

        .page-index .slider-tab {
            padding: 10px 28px;
            background-color: transparent;
            border: 1px solid #ddd;
            border-radius: 25px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
            color: #666;
        }

        .page-index .slider-tab.active {
            background-color: #000;
            color: #fff;
            border-color: #000;
        }

        .page-index .slider-tab:hover:not(.active) {
            border-color: #999;
            color: #333;
        }

        .page-index .slider-tab::before {
            content: '';
            position: absolute;
            top: -10px;
            left: -10px;
            right: -10px;
            bottom: -10px;
            border-radius: 30px;
        }

        .page-index .slider-container {
            position: relative;
            min-height: 580px;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            perspective: 1500px;
            margin-top: -20px;
            padding-top: 10px;
        }

        .page-index .slider-track {
            position: absolute;
            width: 100%;
            min-height: 520px;
            top: 0;
            left: 0;
            display: none;
            align-items: flex-start;
            justify-content: center;
            padding-top: 0;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .page-index .slider-track.active {
            display: flex;
            opacity: 1;
        }

        .page-index .movie-card {
            position: absolute;
            width: 260px;
            transition: all 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            cursor: pointer;
            border-radius: 8px;
            overflow: hidden;
        }

        .page-index .movie-card.center {
            transform: translateX(0) scale(1.15) translateZ(0);
            z-index: 10;
            opacity: 1;
        }

        .page-index .movie-card.left {
            transform: translateX(-340px) scale(0.88) translateZ(-50px);
            z-index: 5;
            opacity: 1;
        }

        .page-index .movie-card.right {
            transform: translateX(340px) scale(0.88) translateZ(-50px);
            z-index: 5;
            opacity: 1;
        }

        .page-index .movie-card.hidden {
            opacity: 0;
            transform: scale(0.5);
            pointer-events: none;
        }

        .page-index .card-poster {
            position: relative;
            width: 100%;
            padding-bottom: 145%;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
            margin-bottom: 14px;
            background-color: #e0e0e0;
            transition: all 0.3s ease;
        }

        .page-index .movie-card.center .card-poster {
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.45);
        }

        .page-index .movie-card:hover .card-poster {
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.5);
            transform: translateY(-2px);
        }

        .page-index .card-poster img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .page-index .movie-card.left .card-poster img,
        .page-index .movie-card.right .card-poster img {
            opacity: 0.8;
        }

        .page-index .movie-card.center .card-poster img {
            opacity: 1;
        }

        .page-index .rank-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            background: rgba(0, 0, 0, 0.85);
            color: #fff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            z-index: 2;
        }

        .page-index .wishlist-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 28px;
            height: 28px;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 2;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .page-index .wishlist-btn:hover {
            background: rgba(0, 0, 0, 0.9);
            transform: scale(1.1);
        }

        .page-index .wishlist-btn i {
            color: #fff;
            font-size: 12px;
        }

        .page-index .wishlist-btn.active i {
            color: #fb4357;
        }

        .page-index .card-info {
            text-align: center;
            padding: 0 4px 8px 4px;
        }

        .page-index .card-title {
            font-size: 16px;
            font-weight: 600;
            color: #000;
            margin-bottom: 0;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 42px;
        }

        .page-index .card-meta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: -12px;
            margin-bottom: 12px;
            font-size: 11px;
            flex-wrap: wrap;
        }

        .page-index .card-meta .separator {
            color: #999;
            margin: 0 2px;
        }

        .page-index .card-meta .card-rating {
            color: #000;
            font-weight: 600;
        }

        .page-index .card-meta .card-rating i {
            color: #ffd700;
            font-size: 12px;
        }

        .page-index .card-booking {
            color: #666;
        }

        .page-index .card-booking strong {
            color: #fb4357;
            font-weight: 700;
        }

        .page-index .card-views {
            color: #666;
        }

        .page-index .book-btn {
            width: calc(100% - 16px);
            margin: 0 auto;
            padding: 9px;
            background-color: #fff;
            color: #000;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: block;
            text-align: center;
        }

        .page-index .book-btn:hover {
            background-color: #fb4357;
            color: #fff;
            border-color: #fb4357;
        }

        .page-index .slider-arrow {
            position: absolute;
            top: 38%;
            transform: translateY(-50%);
            width: 48px;
            height: 48px;
            background: rgba(0, 0, 0, 0.75);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 100;
            font-size: 18px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .page-index .slider-arrow:hover {
            background: rgba(0, 0, 0, 0.95);
            transform: translateY(-50%) scale(1.1);
        }

        .page-index .slider-prev {
            left: 15px;
        }

        .page-index .slider-next {
            right: 15px;
        }

        /* 카테고리 탭 */
        .page-index .movie_category_tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }

        .page-index .category_tab {
            padding: 12px 24px;
            background: transparent;
            color: #666;
            border: none;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .page-index .category_tab.active {
            color: #000;
            border-bottom: 3px solid #fb4357;
            font-weight: bold;
        }

        .page-index .movies_container {
            display: none;
        }

        .page-index .movies_container.active {
            display: block;
        }

        /* 캐러셀 */
        .page-index .movie-carousel {
            overflow: visible;
            position: relative;
            margin-bottom: 48px;
            border: none;
        }

        .page-index .carousel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .page-index .carousel-header .carousel-title {
            font-size: 24px;
            font-weight: bold;
            color: #000;
        }

        .page-index .carousel-header .more_btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 0;
            background-color: transparent;
            color: #000;
            text-decoration: none;
            border: none;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .page-index .carousel-header .more_btn::after {
            content: '>';
            font-size: 18px;
            font-weight: 500;
            transition: transform 0.3s ease;
        }

        .page-index .carousel-header .more_btn:hover {
            color: #fb4357;
        }

        .page-index .carousel-header .more_btn:hover::after {
            transform: translateX(3px);
        }

        .page-index .carousel-container {
            position: relative;
            overflow: hidden;
            border: none;
        }

        .page-index .carousel-track {
            display: flex;
            transition: transform 0.5s ease;
            border: none;
        }

        .page-index .carousel-item {
            flex: 0 0 20%;
            min-width: 20%;
            padding: 0 10px;
            box-sizing: border-box;
            position: relative;
        }

        .page-index .carousel-item>a {
            display: block;
            border: 1px solid #ccc;
            border-radius: 8px;
            overflow: hidden;
            background-color: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .page-index .carousel-poster {
            position: relative;
            width: 100%;
            padding-bottom: 145%;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 8px;
            transition: transform 0.3s ease;
        }

        .page-index .carousel-poster img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* 새로운 캐러셀 정보 스타일 */
        .page-index .carousel-movie-info {
            padding: 4px 4px 8px 4px;
            position: relative;
        }

        .page-index .carousel-rating-badge {
            background-color: transparent;
            color: #000;
            padding: 3px 10px;
            border-radius: 3px;
            font-size: 14px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .page-index .carousel-rating-badge i {
            color: #ffd700;
            font-size: 12px;
        }

        .page-index .carousel-movie-title {
            font-size: 15px;
            font-weight: 600;
            color: #000;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .page-index .carousel-movie-meta {
            display: flex;
            gap: 8px;
            font-size: 12px;
            color: #666;
            margin-bottom: 6px;
        }

        .page-index .carousel-bottom-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .page-index .carousel-wishlist-info {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 13px;
            color: #666;
        }

        .page-index .carousel-wishlist-info i {
            color: #ccc;
            font-size: 14px;
        }

        .page-index .carousel-book-btn {
            flex: 2;
            padding: 11px 24px;
            background-color: #fff;
            color: #e50914;
            border: 1px solid #e50914;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .page-index .carousel-arrow {
            position: absolute;
            top: 45%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            background: rgba(0, 0, 0, 0.95);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 2;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .page-index .carousel-arrow:hover {
            background: rgba(251, 67, 87, 1);
        }

        .page-index .carousel-prev {
            left: -10px;
        }

        .page-index .carousel-next {
            right: -10px;
        }

        /* 광고 슬라이더 */
        .page-index .ad_slider {
            position: relative;
            width: 100%;
            max-width: 1200px;
            height: 160px;
            overflow: hidden;
            margin: 48px auto;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
        }

        .page-index .ad_slides {
            display: flex;
            transition: transform 0.5s ease;
            height: 100%;
        }

        .page-index .ad_slide {
            min-width: 100%;
            height: 100%;
        }

        .page-index .ad_slide img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .page-index .ad_arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            background: rgba(255, 255, 255, 0.9);
            color: #000;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 2;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .page-index .ad_arrow:hover {
            background: #fb4357;
            color: #fff;
        }

        .page-index .ad_prev {
            left: 10px;
        }

        .page-index .ad_next {
            right: 10px;
        }

        .page-index .ad_indicators {
            position: absolute;
            bottom: 12px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 8px;
            z-index: 3;
        }

        .page-index .ad_indicator {
            width: 8px;
            height: 8px;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .page-index .ad_indicator.active {
            background: #fff;
            width: 20px;
            border-radius: 4px;
        }

        /* 이벤트 섹션 */
        .page-index .section_title {
            font-size: 24px;
            font-weight: bold;
            margin: 48px 0 24px 0;
        }

        .page-index .event_container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-bottom: 48px;
        }

        .page-index .event_item {
            border-radius: 10px;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .page-index .event_item:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
        }

        .page-index .event_image {
            width: 100%;
            height: 200px;
            overflow: hidden;
        }

        .page-index .event_image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .page-index .event_item:hover .event_image img {
            transform: scale(1.03);
        }

        .page-index .event_info {
            padding: 20px;
        }

        .page-index .event_title {
            font-size: 18px;
            margin-bottom: 8px;
        }

        .page-index .event_date {
            font-size: 14px;
        }

        .page-index .floating-map-btn {
            position: fixed;
            bottom: 28px;
            right: 28px;
            z-index: 1000;
            background: #fb4357;
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 58px;
            height: 58px;
            box-shadow: 0 4px 12px rgba(251, 67, 87, 0.3);
            font-size: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .page-index .floating-map-btn:hover {
            background: #e5374b;
            transform: translateY(-4px);
        }

        /* 반응형 */
        @media (max-width: 1024px) {
            .page-index .movie-card {
                width: 200px;
            }

            .page-index .movie-card.left {
                transform: translateX(-270px) scale(0.85) translateZ(-50px);
            }

            .page-index .movie-card.right {
                transform: translateX(270px) scale(0.85) translateZ(-50px);
            }

            .page-index .carousel-item {
                flex: 0 0 25%;
                min-width: 25%;
            }
        }

        @media (max-width: 768px) {
            .page-index .slider-wrapper {
                padding: 0 40px;
            }

            .page-index .slider-tabs {
                display: none;
            }

            .page-index .slider-container {
                height: 480px;
            }

            .page-index .movie-card {
                width: 180px;
            }

            .page-index .carousel-item {
                flex: 0 0 33.333%;
                min-width: 33.333%;
            }
        }
    </style>
</head>

<body class="page-index">
    <?php require_once "inc/header.php"; ?>

    <div class="main_wrapper">
        <?php if (count($slider_movies) > 0): ?>
            <div class="main-slider">
                <div class="slider-wrapper">
                    <div class="slider-header">
                        <h2>박스오피스</h2>
                        <div class="slider-tabs">
                            <button class="slider-tab active">인기순</button>
                            <button class="slider-tab">최신순</button>
                        </div>
                    </div>

                    <div class="slider-container">
                        <!-- 인기순 슬라이더 -->
                        <div class="slider-track active" id="slider-popular">
                            <?php foreach ($slider_movies as $index => $movie): ?>
                                <div class="movie-card <?php echo $index === 0 ? 'center' : ($index === 1 ? 'right' : 'hidden'); ?>" data-index="<?php echo $index; ?>" onclick="goToSlide(<?php echo $index; ?>)">
                                    <div class="card-poster">
                                        <img src="<?php echo $movie['poster_image']; ?>" alt="<?php echo htmlspecialchars($movie['title']); ?>">
                                        <div class="rank-badge">No.<?php echo $index + 1; ?></div>
                                        <div class="wishlist-btn" onclick="event.stopPropagation(); toggleWishlist(event, <?php echo $movie['movie_id']; ?>)">
                                            <i class="far fa-heart"></i>
                                        </div>
                                    </div>
                                    <div class="card-info">
                                        <h3 class="card-title"><?php echo htmlspecialchars($movie['title']); ?></h3>
                                        <div class="card-meta">
                                            <span class="card-rating"><i class="fas fa-star"></i> <?php echo number_format($movie['rating'], 1); ?></span>
                                            <span class="separator">|</span>
                                            <span class="card-booking">예매율 <strong><?php echo getBookingRate($movie['audience_count'] ?? 0, $total_audience); ?>%</strong></span>
                                            <span class="separator">|</span>
                                            <span class="card-views">누적 <?php echo number_format(($movie['audience_count'] ?? 0) / 10000, 1); ?>만명</span>
                                        </div>
                                        <a href="movie_detail.php?id=<?php echo $movie['movie_id']; ?>&source=<?php echo $movie['source']; ?>" class="book-btn" onclick="event.stopPropagation();">예매하기</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- 최신순 슬라이더 -->
                        <div class="slider-track" id="slider-latest">
                            <?php foreach ($slider_movies_latest as $index => $movie): ?>
                                <div class="movie-card <?php echo $index === 0 ? 'center' : ($index === 1 ? 'right' : 'hidden'); ?>" data-index="<?php echo $index; ?>" onclick="goToSlideLatest(<?php echo $index; ?>)">
                                    <div class="card-poster">
                                        <img src="<?php echo $movie['poster_image']; ?>" alt="<?php echo htmlspecialchars($movie['title']); ?>">
                                        <div class="rank-badge">No.<?php echo $index + 1; ?></div>
                                        <div class="wishlist-btn" onclick="event.stopPropagation(); toggleWishlist(event, <?php echo $movie['movie_id']; ?>)">
                                            <i class="far fa-heart"></i>
                                        </div>
                                    </div>
                                    <div class="card-info">
                                        <h3 class="card-title"><?php echo htmlspecialchars($movie['title']); ?></h3>
                                        <div class="card-meta">
                                            <span class="card-rating"><i class="fas fa-star"></i> <?php echo number_format($movie['rating'], 1); ?></span>
                                            <span class="separator">|</span>
                                            <span class="card-booking">개봉 <strong><?php echo date('Y.m.d', strtotime($movie['release_date'])); ?></strong></span>
                                            <span class="separator">|</span>
                                            <span class="card-views">누적 <?php echo number_format(($movie['audience_count'] ?? 0) / 10000, 1); ?>만명</span>
                                        </div>
                                        <a href="movie_detail.php?id=<?php echo $movie['movie_id']; ?>&source=<?php echo $movie['source']; ?>" class="book-btn" onclick="event.stopPropagation();">예매하기</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (count($slider_movies) > 1): ?>
                            <div class="slider-arrow slider-prev" onclick="moveSlider('prev')">
                                <i class="fas fa-chevron-left"></i>
                            </div>
                            <div class="slider-arrow slider-next" onclick="moveSlider('next')">
                                <i class="fas fa-chevron-right"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="movie_category_tabs">
            <button class="category_tab active" data-target="now-playing">상영 중</button>
            <button class="category_tab" data-target="upcoming">개봉 예정</button>
        </div>

        <div class="movie-carousel movies_container active" id="now-playing">
            <div class="carousel-header">
                <h2 class="carousel-title">상영 중인 영화</h2>
                <a href="movies.php?category=now_playing" class="more_btn">더보기</a>
            </div>
            <div class="carousel-container">
                <div class="carousel-track" id="now-playing-track">
                    <?php foreach ($now_playing_movies as $movie): ?>
                        <?php if (!empty($movie['poster_image']) && $movie['poster_image'] != 'images/default_poster.jpg'): ?>
                            <div class="carousel-item">
                                <a href="movie_detail.php?id=<?php echo $movie['movie_id']; ?>&source=<?php echo $movie['source']; ?>" style="text-decoration: none; color: inherit;">
                                    <div class="carousel-poster">
                                        <img src="<?php echo $movie['poster_image']; ?>" alt="<?php echo htmlspecialchars($movie['title']); ?>">
                                    </div>
                                    <div class="carousel-movie-info">
                                        <h3 class="carousel-movie-title"><?php echo htmlspecialchars($movie['title']); ?></h3>
                                        <div class="carousel-movie-meta">
                                            <span>예매율 <?php echo getBookingRate($movie['audience_count'] ?? 0, $total_audience); ?>%</span>
                                            <span>개봉일 <?php echo date('Y.m.d', strtotime($movie['release_date'])); ?></span>
                                        </div>
                                        <div class="carousel-bottom-row">
                                            <div class="carousel-rating-badge">
                                                <i class="fas fa-star"></i> <?php echo number_format($movie['rating'], 1); ?>
                                            </div>
                                            <button class="carousel-book-btn" onclick="event.preventDefault(); location.href='movie_detail.php?id=<?php echo $movie['movie_id']; ?>&source=<?php echo $movie['source']; ?>'">예매하기</button>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (count($now_playing_movies) > 5): ?>
                <div class="carousel-arrow carousel-prev" onclick="moveCarousel('now-playing-track', 'prev')">
                    <i class="fas fa-chevron-left"></i>
                </div>
                <div class="carousel-arrow carousel-next" onclick="moveCarousel('now-playing-track', 'next')">
                    <i class="fas fa-chevron-right"></i>
                </div>
            <?php endif; ?>
        </div>

        <div class="movie-carousel movies_container" id="upcoming">
            <div class="carousel-header">
                <h2 class="carousel-title">개봉 예정영화</h2>
                <a href="movies.php?category=upcoming" class="more_btn">더보기</a>
            </div>
            <div class="carousel-container">
                <div class="carousel-track" id="upcoming-track">
                    <?php foreach ($upcoming_movies as $movie): ?>
                        <?php if (!empty($movie['poster_image']) && $movie['poster_image'] != 'images/default_poster.jpg'): ?>
                            <div class="carousel-item">
                                <a href="movie_detail.php?id=<?php echo $movie['movie_id']; ?>&source=<?php echo $movie['source']; ?>" style="text-decoration: none; color: inherit;">
                                    <div class="carousel-poster">
                                        <img src="<?php echo $movie['poster_image']; ?>" alt="<?php echo htmlspecialchars($movie['title']); ?>">
                                    </div>
                                    <div class="carousel-movie-info">
                                        <h3 class="carousel-movie-title"><?php echo htmlspecialchars($movie['title']); ?></h3>
                                        <div class="carousel-movie-meta">
                                            <span>예매율 <?php echo getBookingRate($movie['audience_count'] ?? 0, $total_audience); ?>%</span>
                                            <span>개봉일 <?php echo date('Y.m.d', strtotime($movie['release_date'])); ?></span>
                                        </div>
                                        <div class="carousel-bottom-row">
                                            <div class="carousel-rating-badge">
                                                <i class="fas fa-star"></i> <?php echo number_format($movie['rating'], 1); ?>
                                            </div>
                                            <button class="carousel-book-btn" onclick="event.preventDefault(); location.href='movie_detail.php?id=<?php echo $movie['movie_id']; ?>&source=<?php echo $movie['source']; ?>'">예매하기</button>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (count($upcoming_movies) > 5): ?>
                <div class="carousel-arrow carousel-prev" onclick="moveCarousel('upcoming-track', 'prev')">
                    <i class="fas fa-chevron-left"></i>
                </div>
                <div class="carousel-arrow carousel-next" onclick="moveCarousel('upcoming-track', 'next')">
                    <i class="fas fa-chevron-right"></i>
                </div>
            <?php endif; ?>
        </div>

        <div class="ad_slider">
            <div class="ad_slides">
                <div class="ad_slide">
                    <a href="https://www.megabox.co.kr/event/detail?eventNo=17647" target="_blank">
                        <img src="images/ad2.jpg" alt="광고 2">
                    </a>
                </div>
                <div class="ad_slide">
                    <a href="https://www.megabox.co.kr/event/detail?eventNo=17502" target="_blank">
                        <img src="images/ad3.jpg" alt="광고 3">
                    </a>
                </div>
                <div class="ad_slide">
                    <a href="https://www.megabox.co.kr/event/detail?eventNo=18948" target="_blank">
                        <img src="images/ad4.png" alt="광고 4">
                    </a>
                </div>
            </div>
            <div class="ad_arrow ad_prev"><i class="fas fa-chevron-left"></i></div>
            <div class="ad_arrow ad_next"><i class="fas fa-chevron-right"></i></div>
            <div class="ad_indicators">
                <div class="ad_indicator active" data-index="0"></div>
                <div class="ad_indicator" data-index="1"></div>
                <div class="ad_indicator" data-index="2"></div>
            </div>
        </div>

        <h2 class="section_title">이벤트</h2>
        <div class="event_container">
            <?php if (!empty($events)): ?>
                <?php foreach ($events as $event): ?>
                    <div class="event_item">
                        <a href="event_detail.php?id=<?php echo $event['id']; ?>">
                            <div class="event_image">
                                <img src="<?php echo $event['main_image']; ?>" alt="<?php echo htmlspecialchars($event['title']); ?>">
                            </div>
                            <div class="event_info">
                                <h3 class="event_title"><?php echo htmlspecialchars($event['title']); ?></h3>
                                <p class="event_date">
                                    <?php echo getKoreanDate($event['start_date']); ?> ~
                                    <?php echo $event['end_date'] ? getKoreanDate($event['end_date']) : '무제한'; ?>
                                </p>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="grid-column:1/-1;text-align:center;padding:60px;color:#888;">
                    <h3>등록된 이벤트가 없습니다</h3>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php require_once "inc/footer.php"; ?>

    <script>
        // 박스오피스 슬라이더 변수
        var currentIndex = 0;
        var totalSlides = <?php echo count($slider_movies); ?>;
        var currentIndexLatest = 0;
        var totalSlidesLatest = <?php echo count($slider_movies_latest); ?>;
        var activeSlider = 'popular'; // 'popular' or 'latest'

        function updateSlidePositions() {
            var currentIdx = activeSlider === 'popular' ? currentIndex : currentIndexLatest;
            var total = activeSlider === 'popular' ? totalSlides : totalSlidesLatest;
            var selector = activeSlider === 'popular' ? '#slider-popular .movie-card' : '#slider-latest .movie-card';
            var slides = document.querySelectorAll(selector);

            slides.forEach(function(slide, index) {
                slide.classList.remove('center', 'left', 'right', 'hidden');
                if (index === currentIdx) {
                    slide.classList.add('center');
                } else if (index === (currentIdx - 1 + total) % total) {
                    slide.classList.add('left');
                } else if (index === (currentIdx + 1) % total) {
                    slide.classList.add('right');
                } else {
                    slide.classList.add('hidden');
                }
            });
        }

        function moveSlider(direction) {
            if (activeSlider === 'popular') {
                if (direction === 'next') {
                    currentIndex = (currentIndex + 1) % totalSlides;
                } else {
                    currentIndex = (currentIndex - 1 + totalSlides) % totalSlides;
                }
            } else {
                if (direction === 'next') {
                    currentIndexLatest = (currentIndexLatest + 1) % totalSlidesLatest;
                } else {
                    currentIndexLatest = (currentIndexLatest - 1 + totalSlidesLatest) % totalSlidesLatest;
                }
            }
            updateSlidePositions();
        }

        function goToSlide(index) {
            currentIndex = index;
            updateSlidePositions();
        }

        function goToSlideLatest(index) {
            currentIndexLatest = index;
            updateSlidePositions();
        }

        // 박스오피스 탭 전환 함수
        function switchSliderTab(type) {
            activeSlider = type;

            // 탭 버튼 active 클래스 변경
            document.querySelectorAll('.slider-tab').forEach(function(tab, index) {
                if ((index === 0 && type === 'popular') || (index === 1 && type === 'latest')) {
                    tab.classList.add('active');
                } else {
                    tab.classList.remove('active');
                }
            });

            // 슬라이더 트랙 전환
            document.getElementById('slider-popular').classList.toggle('active', type === 'popular');
            document.getElementById('slider-latest').classList.toggle('active', type === 'latest');

            updateSlidePositions();
        }

        function toggleWishlist(event, movieId) {
            var icon = event.currentTarget.querySelector('i');
            if (icon.classList.contains('far')) {
                icon.classList.remove('far');
                icon.classList.add('fas');
                event.currentTarget.classList.add('active');
            } else {
                icon.classList.remove('fas');
                icon.classList.add('far');
                event.currentTarget.classList.remove('active');
            }
        }

        function moveCarousel(trackId, direction) {
            var track = document.getElementById(trackId);
            var items = track.querySelectorAll('.carousel-item');
            if (items.length <= 5) return;
            var currentX = 0;
            var match = track.style.transform.match(/translateX\((-?\d+)%\)/);
            if (match) currentX = parseInt(match[1]);
            var newX = direction === 'next' ? currentX - 20 : currentX + 20;
            var maxMove = -(items.length - 5) * 20;
            if (newX < maxMove) newX = maxMove;
            if (newX > 0) newX = 0;
            track.style.transform = 'translateX(' + newX + '%)';
        }

        var adCurrentIndex = 0;

        function moveAdSlide(direction) {
            if (direction === 'next') {
                adCurrentIndex = (adCurrentIndex + 1) % 3;
            } else if (direction === 'prev') {
                adCurrentIndex = (adCurrentIndex - 1 + 3) % 3;
            }
            document.querySelector('.ad_slides').style.transform = 'translateX(-' + (adCurrentIndex * 100) + '%)';
            document.querySelectorAll('.ad_indicator').forEach((ind, i) => ind.classList.toggle('active', i === adCurrentIndex));
        }

        window.onload = function() {
            updateSlidePositions();

            // 박스오피스 탭 전환
            document.querySelectorAll('.slider-tab').forEach((tab, index) => {
                tab.onclick = function() {
                    switchSliderTab(index === 0 ? 'popular' : 'latest');
                };
            });

            // 상영중/개봉예정 탭 전환
            document.querySelectorAll('.category_tab').forEach(tab => {
                tab.onclick = function() {
                    document.querySelectorAll('.category_tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.movies_container').forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    document.getElementById(this.dataset.target).classList.add('active');
                };
            });

            // 광고 슬라이더
            document.querySelector('.ad_prev').onclick = () => moveAdSlide('prev');
            document.querySelector('.ad_next').onclick = () => moveAdSlide('next');
            document.querySelectorAll('.ad_indicator').forEach(ind => {
                ind.onclick = function() {
                    adCurrentIndex = parseInt(this.dataset.index);
                    moveAdSlide('stay');
                };
            });
            setInterval(() => moveAdSlide('next'), 5000);
        };
    </script>

    <button class="floating-map-btn" onclick="openKakaoMap()">
        <i class="fas fa-map-marker-alt"></i>
    </button>

    <div id="mapModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:2000;justify-content:center;align-items:center;">
        <div style="position:relative;width:600px;height:400px;background:#fff;border-radius:8px;overflow:hidden;">
            <div id="map" style="width:100%;height:100%;"></div>
        </div>
    </div>

    <script src="js/kakaomap.js"></script>
    <?php include 'inc/kakaomap_api.php'; ?>
</body>

</html>