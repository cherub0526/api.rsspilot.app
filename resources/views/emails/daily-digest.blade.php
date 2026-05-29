<!doctype html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Audistilizer — 每日摘要通知</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, sans-serif;
      background: #f4f6fb;
      color: #1a1a1a;
      line-height: 1.6;
      padding: 40px 16px 60px;
    }

    .email-wrapper {
      max-width: 640px;
      margin: 0 auto;
      background: #ffffff;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 1px 4px rgba(0,0,0,.06), 0 4px 24px rgba(0,0,0,.07);
    }

    .email-header {
      background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 60%, #3b82f6 100%);
      padding: 36px 40px 32px;
      text-align: center;
    }
    .email-logo {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 24px;
    }
    .email-logo img {
      width: 32px; height: 32px;
      object-fit: contain;
      filter: brightness(0) invert(1);
    }
    .email-logo-name {
      font-size: 1.125rem;
      font-weight: 800;
      color: #fff;
      letter-spacing: -0.02em;
    }
    .email-header-greeting {
      font-size: 0.875rem;
      font-weight: 500;
      color: rgba(255,255,255,0.75);
      margin-bottom: 8px;
    }
    .email-header-title {
      font-size: clamp(1.25rem, 4vw, 1.625rem);
      font-weight: 800;
      color: #fff;
      letter-spacing: -0.03em;
      line-height: 1.25;
      margin-bottom: 12px;
    }
    .email-header-meta {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(255,255,255,0.15);
      border-radius: 20px;
      padding: 5px 14px;
    }
    .email-header-meta span {
      font-size: 0.8125rem;
      font-weight: 600;
      color: rgba(255,255,255,0.9);
    }
    .email-header-meta .dot {
      width: 4px; height: 4px;
      background: rgba(255,255,255,0.5);
      border-radius: 50%;
    }

    .email-body { padding: 32px 40px; }

    .email-intro {
      font-size: 0.9375rem;
      color: #52525b;
      line-height: 1.75;
      margin-bottom: 28px;
      padding-bottom: 24px;
      border-bottom: 1px solid #f1f5f9;
    }
    .email-intro strong { color: #1a1a1a; font-weight: 600; }

    .video-card {
      margin-bottom: 24px;
      border: 1px solid #f1f5f9;
      border-radius: 12px;
      overflow: hidden;
      background: #fafafa;
    }
    .video-card:last-of-type { margin-bottom: 0; }

    .video-thumb {
      position: relative;
      width: 100%;
      aspect-ratio: 16/9;
      overflow: hidden;
    }
    .video-thumb-inner {
      width: 100%; height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
    }
    .video-thumb-overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(to top, rgba(0,0,0,0.55) 0%, transparent 50%);
    }
    .video-thumb-duration {
      position: absolute;
      bottom: 10px; right: 10px;
      background: rgba(0,0,0,0.75);
      color: #fff;
      font-size: 0.75rem;
      font-weight: 600;
      padding: 2px 7px;
      border-radius: 4px;
    }
    .video-thumb-new {
      position: absolute;
      top: 10px; left: 10px;
      background: #2563eb;
      color: #fff;
      font-size: 0.6875rem;
      font-weight: 700;
      padding: 3px 8px;
      border-radius: 4px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .video-content { padding: 18px 20px 20px; }
    .video-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
    }
    .video-channel { font-size: 0.75rem; font-weight: 600; color: #2563eb; }
    .video-sep { width: 3px; height: 3px; background: #d4d4d4; border-radius: 50%; flex-shrink: 0; }
    .video-date { font-size: 0.75rem; color: #a3a3a3; font-weight: 500; }
    .video-title {
      font-size: 1rem;
      font-weight: 700;
      color: #1a1a1a;
      letter-spacing: -0.02em;
      line-height: 1.4;
      margin-bottom: 14px;
    }

    .tldr-block {
      background: #eff6ff;
      border-left: 3px solid #2563eb;
      border-radius: 0 8px 8px 0;
      padding: 10px 14px;
      margin-bottom: 14px;
    }
    .tldr-label {
      font-size: 0.6875rem;
      font-weight: 700;
      color: #2563eb;
      text-transform: uppercase;
      letter-spacing: 0.07em;
      margin-bottom: 4px;
    }
    .tldr-text { font-size: 0.875rem; color: #1e40af; line-height: 1.6; font-weight: 500; }

    .keypoints-label {
      font-size: 0.75rem;
      font-weight: 700;
      color: #737373;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 8px;
    }
    .keypoints-list {
      list-style: none;
      padding: 0; margin-bottom: 18px;
      display: flex; flex-direction: column; gap: 6px;
    }
    .keypoints-list li {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      font-size: 0.875rem;
      color: #52525b;
      line-height: 1.6;
    }
    .keypoints-list li::before {
      content: '';
      width: 6px; height: 6px;
      background: #2563eb;
      border-radius: 50%;
      flex-shrink: 0;
      margin-top: 7px;
    }

    .cta-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .cta-btn {
      display: inline-block;
      background: #2563eb;
      color: #fff;
      font-size: 0.875rem;
      font-weight: 600;
      padding: 9px 20px;
      border-radius: 8px;
      text-decoration: none;
      letter-spacing: -0.01em;
    }
    .cta-views { font-size: 0.8125rem; color: #a3a3a3; font-weight: 500; }

    .email-summary-bar {
      margin: 28px 0 0;
      padding: 20px;
      background: #f8faff;
      border: 1px solid #dbeafe;
      border-radius: 10px;
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .summary-bar-icon {
      width: 40px; height: 40px;
      background: #eff6ff;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .summary-bar-text { flex: 1; }
    .summary-bar-text strong {
      display: block;
      font-size: 0.9375rem;
      font-weight: 700;
      color: #1a1a1a;
      margin-bottom: 3px;
    }
    .summary-bar-text p { font-size: 0.8125rem; color: #737373; line-height: 1.5; }
    .summary-bar-cta {
      flex-shrink: 0;
      display: inline-block;
      background: #fff;
      border: 1.5px solid #2563eb;
      color: #2563eb;
      font-size: 0.8125rem;
      font-weight: 600;
      padding: 7px 14px;
      border-radius: 8px;
      text-decoration: none;
    }

    .email-footer {
      background: #f8f9fa;
      border-top: 1px solid #f1f5f9;
      padding: 24px 40px;
      text-align: center;
    }
    .footer-logo {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      margin-bottom: 12px;
    }
    .footer-logo img { width: 20px; height: 20px; object-fit: contain; opacity: 0.4; }
    .footer-logo-name { font-size: 0.8125rem; font-weight: 700; color: #a3a3a3; letter-spacing: -0.01em; }
    .footer-links {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 4px;
      margin-bottom: 10px;
      flex-wrap: wrap;
    }
    .footer-links a { font-size: 0.75rem; color: #a3a3a3; text-decoration: none; padding: 2px 6px; }
    .footer-links span { font-size: 0.75rem; color: #d4d4d4; }
    .footer-copy { font-size: 0.75rem; color: #c4c4c4; }
    .footer-unsubscribe { margin-top: 8px; font-size: 0.75rem; color: #d4d4d4; }
    .footer-unsubscribe a { color: #a3a3a3; text-decoration: underline; text-underline-offset: 2px; }

    @media (max-width: 640px) {
      body { padding: 16px 8px 40px; }
      .email-header { padding: 28px 20px 24px; }
      .email-body { padding: 24px 20px; }
      .email-footer { padding: 20px; }
      .cta-row { flex-direction: column; align-items: flex-start; }
      .email-summary-bar { flex-direction: column; align-items: flex-start; gap: 10px; }
      .summary-bar-cta { width: 100%; text-align: center; }
    }
  </style>
</head>
<body>

  <div class="email-wrapper">

    <div class="email-header">
      <div class="email-logo">
        <img src="{{ asset('/logo.png') }}" alt="Audistilizer">
        <span class="email-logo-name">Audistilizer</span>
      </div>
      <p class="email-header-greeting">嗨，{{ $userName }}！今天是 {{ $date }}</p>
      <h1 class="email-header-title">今日新增了 {{ $videoCount }} 部影片摘要</h1>
      <div class="email-header-meta">
        <span>🎬 {{ $videoCount }} 部影片</span>
        <div class="dot"></div>
        <span>AI 摘要已備妥</span>
        <div class="dot"></div>
        <span>可立即查看</span>
      </div>
    </div>

    <div class="email-body">

      <p class="email-intro">
        您訂閱的頻道今天新增了 <strong>{{ $videoCount }} 部影片</strong>，我們已自動產出 AI 摘要，讓您快速掌握每部影片的重點內容，不必花時間完整觀看每一部。
      </p>

      @foreach ($videos as $video)
      <div class="video-card">
        <div class="video-thumb">
          <div class="video-thumb-inner" style="background: {{ $video['thumbnailGradient'] }};">
            {{ $video['thumbnailEmoji'] }}
          </div>
          <div class="video-thumb-overlay"></div>
          <span class="video-thumb-new">New</span>
          <span class="video-thumb-duration">{{ $video['duration'] }}</span>
        </div>
        <div class="video-content">
          <div class="video-meta">
            <span class="video-channel">{{ $video['channel'] }}</span>
            <div class="video-sep"></div>
            <span class="video-date">{{ $video['publishedAt'] }}</span>
          </div>
          <h2 class="video-title">{{ $video['title'] }}</h2>
          <div class="tldr-block">
            <div class="tldr-label">TL;DR</div>
            <p class="tldr-text">{{ $video['tldr'] }}</p>
          </div>
          <p class="keypoints-label">重點摘要</p>
          <ul class="keypoints-list">
            @foreach ($video['keyPoints'] as $point)
            <li>{{ $point }}</li>
            @endforeach
          </ul>
          <div class="cta-row">
            <a class="cta-btn" href="{{ $video['url'] }}">查看完整摘要 →</a>
            <span class="cta-views">{{ number_format($video['viewCount']) }} 次觀看</span>
          </div>
        </div>
      </div>
      @endforeach

      <div class="email-summary-bar">
        <div class="summary-bar-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
          </svg>
        </div>
        <div class="summary-bar-text">
          <strong>前往 Dashboard 查看所有影片</strong>
          <p>您的訂閱共有 {{ $channelCount }} 個頻道・已累積 {{ $totalMediaCount }} 部影片摘要</p>
        </div>
        <a class="summary-bar-cta" href="{{ $dashboardUrl }}">開啟 Dashboard</a>
      </div>

    </div>

    <div class="email-footer">
      <div class="footer-logo">
        <img src="{{ asset('/logo.png') }}" alt="">
        <span class="footer-logo-name">Audistilizer</span>
      </div>
      <div class="footer-links">
        <a href="{{ $dashboardUrl }}">Dashboard</a>
        <span>·</span>
        <a href="{{ $pricingUrl }}">升級方案</a>
        <span>·</span>
        <a href="{{ $termsUrl }}">服務條款</a>
        <span>·</span>
        <a href="{{ $privacyUrl }}">隱私政策</a>
      </div>
      <p class="footer-copy">© {{ date('Y') }} Audistilizer. All rights reserved.</p>
      <p class="footer-unsubscribe">
        不想再收到每日摘要通知？<a href="{{ $unsubscribeUrl }}">取消訂閱</a> 或 <a href="{{ $dashboardUrl }}">調整通知設定</a>
      </p>
    </div>

  </div>

</body>
</html>
