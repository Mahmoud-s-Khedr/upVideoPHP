/**
 * Video Player — Custom HLS player powered by hls.js
 *
 * Reads configuration from window.__VP_CONFIG which must contain:
 *   - mode: 'embed' | 'watch'
 *   - videoUuid: string
 *   - baseUrl: string
 *   - bootstrapUrl: string (embed mode) OR bootstrap: object (watch mode, pre-loaded)
 *   - parentOrigin: string (embed mode only)
 *   - embedToken: string (embed mode only)
 */
(function () {
  'use strict';

  var cfg = window.__VP_CONFIG;
  if (!cfg) return;

  // -------------------------------------------------------------------------
  // DOM references
  // -------------------------------------------------------------------------
  var $ = function (id) { return document.getElementById(id); };

  var container       = $('vp-container');
  var video           = $('vp-video');
  var poster          = $('vp-poster');
  var posterImg       = $('vp-poster-img');
  var bigPlay         = $('vp-big-play');
  var controls        = $('vp-controls');
  var playBtn         = $('vp-play-btn');
  var iconPlay        = $('vp-icon-play');
  var iconPause       = $('vp-icon-pause');
  var muteBtn         = $('vp-mute-btn');
  var iconVol         = $('vp-icon-vol');
  var iconMuted       = $('vp-icon-muted');
  var volumeSlider    = $('vp-volume');
  var timeDisplay     = $('vp-time');
  var progressWrap    = $('vp-progress-wrap');
  var progressPlayed  = $('vp-progress-played');
  var progressBuffered = $('vp-progress-buffered');
  var progressInput   = $('vp-progress');
  var qualityWrap     = $('vp-quality-wrap');
  var qualityBtn      = $('vp-quality-btn');
  var qualityMenu     = $('vp-quality-menu');
  var speedWrap       = $('vp-speed-wrap');
  var speedBtn        = $('vp-speed-btn');
  var speedMenu       = $('vp-speed-menu');
  var captionWrap     = $('vp-caption-wrap');
  var captionBtn      = $('vp-caption-btn');
  var captionMenu     = $('vp-caption-menu');
  var fsBtn           = $('vp-fullscreen-btn');
  var iconFs          = $('vp-icon-fs');
  var iconFsExit      = $('vp-icon-fs-exit');
  var titleBar        = $('vp-title-bar');
  var titleText       = $('vp-title-text');
  var logoEl          = $('vp-logo');
  var logoImg         = $('vp-logo-img');
  var pendingScreen   = $('vp-pending');
  var errorScreen     = $('vp-error');
  var errorMsg        = $('vp-error-msg');
  var origBanner      = $('vp-original-banner');
  var prerollWrap       = $('vp-preroll');
  var prerollVideo      = $('vp-preroll-video');
  var prerollSkip       = $('vp-preroll-skip');
  var prerollCountdown  = $('vp-preroll-countdown');
  var prerollClickLink  = $('vp-preroll-click');
  var postrollWrap      = $('vp-postroll');
  var postrollVideo     = $('vp-postroll-video');
  var postrollSkip      = $('vp-postroll-skip');
  var postrollCountdown = $('vp-postroll-countdown');
  var postrollClickLink = $('vp-postroll-click');
  var adblockOverlay    = $('vp-adblock-overlay');
  var adblockRetry      = $('vp-adblock-retry');
  var directFrame       = $('vp-direct-frame');
  var directFrameClose  = $('vp-direct-frame-close');
  var directFrameIframe = $('vp-direct-frame-iframe');
  var resumeToast       = $('vp-resume-toast');
  var resumeText      = $('vp-resume-text');
  var resumeYes       = $('vp-resume-yes');
  var resumeNo        = $('vp-resume-no');

  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------
  var hls              = null;
  var hlsLevels        = [];
  var currentQuality   = -1; // -1 = auto
  var pollTimer        = null;
  var controlsTimer    = null;
  var isSeeking        = false;
  var prerollPlaying   = false;
  var speeds           = [0.5, 0.75, 1, 1.25, 1.5, 2];
  var currentSpeed     = 1;
  var activeMenu       = null;
  var bootstrapData    = null;
  var adSessionId      = null;
  var midrollFired     = [];
  var postrollPlayed   = false;
  var prerollPlayed    = false;
  var hlsReady         = false;
  var pendingPlayAfterGate = false;
  var firstPlayGateDone    = false;
  var firstPlayGateRunning = false;
  var resumePromptTime     = 0;
  var resumePromptPending  = false;
  var directFrameOnClose   = null;

  // -------------------------------------------------------------------------
  // Ad session ID
  // -------------------------------------------------------------------------
  function getAdSessionId() {
    if (adSessionId) return adSessionId;
    try { adSessionId = sessionStorage.getItem('vp_ad_sid'); } catch (e) {}
    if (!adSessionId) {
      adSessionId = Math.random().toString(36).slice(2) + Date.now().toString(36);
      try { sessionStorage.setItem('vp_ad_sid', adSessionId); } catch (e) {}
    }
    return adSessionId;
  }

  function trackAdEvent(position, event, cueIndex) {
    if (!bootstrapData) return;
    try {
      fetch(cfg.baseUrl + '/api/ad-event', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          video_uuid: bootstrapData.video_uuid,
          position:   position,
          event:      event,
          cue_index:  (cueIndex !== undefined && cueIndex !== null) ? cueIndex : null,
          session_id: getAdSessionId(),
        }),
        credentials: 'omit',
        keepalive: true,
      });
    } catch (e) {}
    // Fire-and-forget — ignore failures
  }

  // -------------------------------------------------------------------------
  // Init
  // -------------------------------------------------------------------------
  function init() {
    if (cfg.mode === 'watch' && cfg.bootstrap) {
      onBootstrap(cfg.bootstrap);
    } else if (cfg.bootstrapUrl) {
      fetchBootstrap(cfg.bootstrapUrl);
    }

    bindEvents();
    buildSpeedMenu();
    preventContextMenu();
    postMessage('player.ready');
  }

  function fetchBootstrap(url) {
    fetch(url, { credentials: 'omit' })
      .then(function (r) {
        if (!r.ok) throw new Error('Bootstrap failed: ' + r.status);
        return r.json();
      })
      .then(onBootstrap)
      .catch(function (e) { showError(e.message); });
  }

  // -------------------------------------------------------------------------
  // Bootstrap handler — state machine
  // -------------------------------------------------------------------------
  function onBootstrap(data) {
    bootstrapData = data;
    applyEmbedSettings(data.embed_settings || {});
    applyTitle(data.title, data.embed_settings);
    applyPoster(data.poster_url);

    var mode = data.playback_mode;

    if (mode === 'pending') {
      showPending();
      schedulePoll(data.poll_after_ms);
    } else if (mode === 'original') {
      showOriginalBanner();
      startOriginalPlayback(data.original_url);
      schedulePoll(data.poll_after_ms);
    } else if (mode === 'hls') {
      clearPoll();
      hidePending();
      setupSubtitles(data.subtitle_tracks || []);
      checkResumeAndPlay(data);
    } else if (mode === 'error') {
      showError('This video is unavailable.');
    }
  }

  function checkResumeAndPlay(data) {
    var saved = getSavedPosition(data.video_uuid);
    resumePromptTime = saved;
    resumePromptPending = saved > 10 && data.duration_sec && saved < data.duration_sec - 10;
    bindPostroll(data.embed_settings);
    setupMidroll(data.embed_settings);
    if (!hls && !video.src) {
      startHlsPlayback(data.master_playlist_url, data.embed_settings, function () {
        hlsReady = true;
        if (pendingPlayAfterGate) {
          playMainContent();
        }
      });
    } else if (hls || video.src) {
      hlsReady = true;
    }
  }

  // -------------------------------------------------------------------------
  // HLS playback
  // -------------------------------------------------------------------------
  function startHlsPlayback(playlistUrl, settings, onReady) {
    if (!playlistUrl) return showError('No playlist available.');

    // Safari native HLS
    if (video.canPlayType('application/vnd.apple.mpegurl') && !window.Hls) {
      hlsLevels = [];
      buildQualityMenu();
      video.src = playlistUrl;
      video.addEventListener('loadedmetadata', function onNativeMetadata() {
        video.removeEventListener('loadedmetadata', onNativeMetadata);
        hlsReady = true;
        if (typeof onReady === 'function') onReady();
        onHlsReady();
      });
      return;
    }

    if (!window.Hls || !Hls.isSupported()) {
      return showError('HLS playback not supported in this browser.');
    }

    hls = new Hls({
      startLevel: -1, // auto
      capLevelToPlayerSize: true,
    });

    hls.loadSource(playlistUrl);
    hls.attachMedia(video);

    hls.on(Hls.Events.MANIFEST_PARSED, function (_, data) {
      hlsLevels = data.levels || [];
      buildQualityMenu();
      hlsReady = true;
      if (typeof onReady === 'function') onReady();
      onHlsReady();
    });

    hls.on(Hls.Events.LEVEL_SWITCHED, function (_, data) {
      currentQuality = data.level;
      updateQualityBtn();
    });

    hls.on(Hls.Events.ERROR, function (_, data) {
      if (data.fatal) {
        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
          hls.startLoad();
        } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
          hls.recoverMediaError();
        } else {
          showError('Playback error.');
        }
      }
    });
  }

  function onHlsReady() {
    poster.style.display = 'none';
    // Don't auto-play in embed unless user already clicked
  }

  // -------------------------------------------------------------------------
  // Original file playback
  // -------------------------------------------------------------------------
  function startOriginalPlayback(url) {
    if (!url) return showPending();
    poster.style.display = 'none';
    video.src = url;
  }

  // -------------------------------------------------------------------------
  // Subtitle support
  // -------------------------------------------------------------------------
  function setupSubtitles(tracks) {
    if (!tracks || tracks.length === 0) return;

    captionWrap.style.display = '';
    captionMenu.innerHTML = '';

    // Off option
    var offItem = makeMenuItem('Off', true, function () {
      setAllTracksMode('disabled');
      updateCaptionMenu(-1);
      closeMenus();
    });
    captionMenu.appendChild(offItem);

    tracks.forEach(function (t, i) {
      var track = document.createElement('track');
      track.kind = t.is_forced ? 'forced' : 'subtitles';
      track.label = t.label;
      track.srclang = t.language_code;
      track.src = t.src;
      track.mode = 'disabled';
      video.appendChild(track);

      // Capture the TextTrack reference immediately after appending so the
      // closure uses the correct object regardless of pre-existing tracks.
      var textTrack = track.track;

      var item = makeMenuItem(t.label, false, function () {
        setAllTracksMode('disabled');
        textTrack.mode = 'showing';
        var realIdx = Array.from(video.textTracks).indexOf(textTrack);
        updateCaptionMenu(realIdx);
        closeMenus();
      });
      item.dataset.index = Array.from(video.textTracks).indexOf(textTrack);
      captionMenu.appendChild(item);
    });
  }

  function setAllTracksMode(mode) {
    for (var i = 0; i < video.textTracks.length; i++) {
      video.textTracks[i].mode = mode;
    }
  }

  function updateCaptionMenu(activeIdx) {
    var items = captionMenu.querySelectorAll('.vp-dropdown-item');
    items.forEach(function (el, i) {
      el.classList.toggle('vp-active', i === activeIdx + 1);
    });
  }

  // -------------------------------------------------------------------------
  // Quality menu
  // -------------------------------------------------------------------------
  function buildQualityMenu() {
    qualityMenu.innerHTML = '';

    var autoItem = makeMenuItem('Auto', true, function () {
      if (hls) hls.currentLevel = -1;
      currentQuality = -1;
      updateQualityBtn();
      closeMenus();
    });
    qualityMenu.appendChild(autoItem);

    hlsLevels.forEach(function (level, i) {
      var label = level.height + 'p';
      var item = makeMenuItem(label, false, function () {
        if (hls) hls.currentLevel = i;
        currentQuality = i;
        updateQualityBtn();
        closeMenus();
      });
      item.dataset.level = i;
      qualityMenu.appendChild(item);
    });
  }

  function updateQualityBtn() {
    if (currentQuality === -1) {
      qualityBtn.textContent = 'Auto';
    } else if (hlsLevels[currentQuality]) {
      qualityBtn.textContent = hlsLevels[currentQuality].height + 'p';
    }

    var items = qualityMenu.querySelectorAll('.vp-dropdown-item');
    items.forEach(function (el, i) {
      el.classList.toggle('vp-active', i === (currentQuality + 1));
    });
  }

  // -------------------------------------------------------------------------
  // Speed menu
  // -------------------------------------------------------------------------
  function buildSpeedMenu() {
    speedMenu.innerHTML = '';
    speeds.forEach(function (s) {
      var item = makeMenuItem(s + 'x', s === 1, function () {
        currentSpeed = s;
        video.playbackRate = s;
        speedBtn.textContent = s + 'x';
        updateSpeedMenu();
        closeMenus();
      });
      speedMenu.appendChild(item);
    });
  }

  function updateSpeedMenu() {
    var items = speedMenu.querySelectorAll('.vp-dropdown-item');
    items.forEach(function (el, i) {
      el.classList.toggle('vp-active', speeds[i] === currentSpeed);
    });
  }

  // -------------------------------------------------------------------------
  // Dropdown helpers
  // -------------------------------------------------------------------------
  function makeMenuItem(label, active, onClick) {
    var btn = document.createElement('button');
    btn.className = 'vp-dropdown-item' + (active ? ' vp-active' : '');
    btn.textContent = label;
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      onClick();
    });
    return btn;
  }

  function toggleMenu(menu) {
    if (activeMenu && activeMenu !== menu) {
      activeMenu.style.display = 'none';
    }
    var isOpen = menu.style.display !== 'none';
    menu.style.display = isOpen ? 'none' : '';
    activeMenu = isOpen ? null : menu;
  }

  function closeMenus() {
    if (activeMenu) {
      activeMenu.style.display = 'none';
      activeMenu = null;
    }
  }

  // -------------------------------------------------------------------------
  // Embed settings application
  // -------------------------------------------------------------------------
  function applyEmbedSettings(settings) {
    if (!settings) return;

    // Accent color
    if (settings.accent_color) {
      container.style.setProperty('--vp-accent', settings.accent_color);
    }

    // Logo
    if (settings.logo_url) {
      logoImg.src = settings.logo_url;
      logoEl.className = 'vp-logo ' + (settings.logo_position || 'top-right');
      logoEl.style.display = '';
    }
  }

  function applyTitle(title, settings) {
    if (!settings || !settings.title_visible || !title) return;
    titleText.textContent = title;
    titleBar.style.display = '';
  }

  function applyPoster(url) {
    if (!url) return;
    posterImg.src = url;
    posterImg.style.display = '';
  }

  function normalizedSourceKind(kind, url) {
    if (kind === 'mp4' || kind === 'vast') return kind;
    return url ? 'mp4' : 'none';
  }

  function getPrerollSlot(settings) {
    var url = settings && settings.preroll_url;
    var kind = normalizedSourceKind(settings && settings.preroll_source_kind, url);
    if (kind === 'none' || !url) return null;
    return {
      kind: kind,
      url: url,
      skip_after: settings.preroll_skip_after !== undefined ? settings.preroll_skip_after : 5,
      click_url: settings.preroll_click_url || null
    };
  }

  function getPostrollSlot(settings) {
    var url = settings && settings.postroll_url;
    var kind = normalizedSourceKind(settings && settings.postroll_source_kind, url);
    if (kind === 'none' || !url) return null;
    return {
      kind: kind,
      url: url,
      skip_after: settings.postroll_skip_after !== undefined ? settings.postroll_skip_after : 5,
      click_url: settings.postroll_click_url || null
    };
  }

  function getCueSlot(cue) {
    if (!cue || !cue.url) return null;
    return {
      kind: normalizedSourceKind(cue.source_kind, cue.url),
      url: cue.url,
      skip_after: cue.skip_after !== undefined ? cue.skip_after : 5,
      click_url: cue.click_url || null
    };
  }

  function resolveAdSlot(slot, onResolved) {
    if (!slot || !slot.url) {
      onResolved(null);
      return;
    }
    if (slot.kind !== 'vast') {
      onResolved(slot);
      return;
    }

    resolveVastTag(slot.url, 0)
      .then(function (resolved) {
        onResolved({
          kind: 'mp4',
          url: resolved.url,
          skip_after: slot.skip_after !== undefined ? slot.skip_after : 5,
          click_url: resolved.click_url || slot.click_url || null
        });
      })
      .catch(function (err) {
        console.warn('VAST resolution failed', err);
        onResolved(null);
      });
  }

  function resolveVastTag(url, depth) {
    if (depth > 5) {
      return Promise.reject(new Error('Too many VAST wrappers'));
    }

    return fetch(url, { credentials: 'omit' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('VAST request failed: ' + response.status);
        }
        return response.text();
      })
      .then(function (xmlText) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(xmlText, 'application/xml');
        if (doc.getElementsByTagName('parsererror').length) {
          throw new Error('Invalid VAST XML');
        }

        var wrapperNode = doc.querySelector('Wrapper > VASTAdTagURI, Wrapper VASTAdTagURI');
        if (wrapperNode && wrapperNode.textContent && wrapperNode.textContent.trim()) {
          return resolveVastTag(wrapperNode.textContent.trim(), depth + 1);
        }

        var mediaFiles = Array.prototype.slice.call(doc.getElementsByTagName('MediaFile'));
        var mediaUrl = pickPlayableMediaFile(mediaFiles);
        if (!mediaUrl) {
          throw new Error('No playable VAST media file');
        }

        var clickNode = doc.querySelector('VideoClicks > ClickThrough, ClickThrough');
        return {
          url: mediaUrl,
          click_url: clickNode && clickNode.textContent ? clickNode.textContent.trim() : null
        };
      });
  }

  function pickPlayableMediaFile(mediaFiles) {
    var mp4Candidates = [];
    var fallbackCandidates = [];

    mediaFiles.forEach(function (node) {
      if (!node || !node.textContent) return;
      var url = node.textContent.trim();
      if (!url) return;
      var mime = (node.getAttribute('type') || '').toLowerCase();
      var candidate = { url: url, mime: mime };
      if (mime === 'video/mp4') {
        mp4Candidates.push(candidate);
      } else {
        fallbackCandidates.push(candidate);
      }
    });

    if (mp4Candidates.length > 0) {
      return mp4Candidates[0].url;
    }

    for (var i = 0; i < fallbackCandidates.length; i++) {
      var candidate = fallbackCandidates[i];
      if (!candidate.mime || video.canPlayType(candidate.mime)) {
        return candidate.url;
      }
    }

    return null;
  }

  function hasConfiguredVideoAds(settings) {
    var preroll = getPrerollSlot(settings);
    var postroll = getPostrollSlot(settings);
    var cues = settings && settings.midroll_cues;
    return !!(preroll || postroll || (cues && cues.length));
  }

  function detectAdBlocker() {
    var bait = document.createElement('div');
    bait.className = 'adsbox text-ad pub_300x250';
    bait.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;';
    document.body.appendChild(bait);

    var blocked = false;
    try {
      var style = window.getComputedStyle(bait);
      blocked = bait.offsetParent === null || bait.offsetHeight === 0 || bait.offsetWidth === 0 ||
        style.display === 'none' || style.visibility === 'hidden';
    } catch (e) {
      blocked = false;
    }

    document.body.removeChild(bait);
    return blocked;
  }

  function showAdblockOverlay() {
    if (adblockOverlay) adblockOverlay.style.display = '';
  }

  function hideAdblockOverlay() {
    if (adblockOverlay) adblockOverlay.style.display = 'none';
  }

  function maybeBlockForAdblock(settings, onDone) {
    if (!settings || !settings.force_disable_adblock || !hasConfiguredVideoAds(settings)) {
      hideAdblockOverlay();
      onDone(false);
      return;
    }

    if (detectAdBlocker()) {
      showAdblockOverlay();
      onDone(true);
      return;
    }

    hideAdblockOverlay();
    onDone(false);
  }

  function getDirectPlayStorageKey() {
    if (!bootstrapData) return 'vp_direct_played';
    return 'vp_direct_played_' + bootstrapData.video_uuid;
  }

  function hasDirectPlayAlreadyRun() {
    try {
      return sessionStorage.getItem(getDirectPlayStorageKey()) === '1';
    } catch (e) {
      return false;
    }
  }

  function markDirectPlayRun() {
    try {
      sessionStorage.setItem(getDirectPlayStorageKey(), '1');
    } catch (e) {}
  }

  function openDirectFrame(url, onClose) {
    if (!directFrame || !directFrameIframe) {
      if (typeof onClose === 'function') onClose();
      return;
    }
    directFrameOnClose = onClose || null;
    directFrameIframe.src = url;
    directFrame.style.display = '';
  }

  function closeDirectFrame() {
    if (!directFrame || !directFrameIframe) return;
    directFrame.style.display = 'none';
    directFrameIframe.src = 'about:blank';
    var onClose = directFrameOnClose;
    directFrameOnClose = null;
    if (typeof onClose === 'function') {
      onClose();
    }
  }

  function maybeRunDirectPlay(settings, onDone) {
    if (!settings || !settings.direct_play_url || hasDirectPlayAlreadyRun()) {
      onDone(true);
      return;
    }

    markDirectPlayRun();

    if (settings.direct_play_mode === 'redirect') {
      window.location.href = settings.direct_play_url;
      onDone(false);
      return;
    }

    if (settings.direct_play_mode === 'iframe') {
      openDirectFrame(settings.direct_play_url, function () {
        onDone(true);
      });
      return;
    }

    var popup = null;
    try {
      popup = window.open(settings.direct_play_url, '_blank', 'noopener,noreferrer');
    } catch (e) {
      popup = null;
    }

    if (popup && !popup.closed) {
      onDone(true);
      return;
    }

    if (settings.direct_popup_bypass_iframe) {
      openDirectFrame(settings.direct_play_url, function () {
        onDone(true);
      });
      return;
    }

    onDone(true);
  }

  function runFirstPlayGate(onDone, onAbort) {
    var settings = bootstrapData ? (bootstrapData.embed_settings || {}) : {};

    maybeBlockForAdblock(settings, function (blocked) {
      if (blocked) {
        if (typeof onAbort === 'function') onAbort();
        return;
      }

      maybeRunDirectPlay(settings, function (shouldContinue) {
        if (!shouldContinue) {
          if (typeof onAbort === 'function') onAbort();
          return;
        }

        maybePreroll(settings, function () {
          onDone();
        });
      });
    });
  }

  function playMainContent() {
    if (bootstrapData && bootstrapData.playback_mode === 'hls') {
      if (!hlsReady) {
        pendingPlayAfterGate = true;
        return;
      }

      pendingPlayAfterGate = false;
      if (resumePromptPending) {
        resumePromptPending = false;
        showResumeToast(
          resumePromptTime,
          function () {
            video.currentTime = resumePromptTime;
            video.play().catch(function () {});
          },
          function () {
            video.play().catch(function () {});
          }
        );
        return;
      }
    }

    video.play().catch(function () {});
  }

  // -------------------------------------------------------------------------
  // Generic ad overlay engine
  // -------------------------------------------------------------------------
  function playAdOverlay(wrapEl, videoEl, skipEl, countdownEl, clickEl, settings, position, cueIndex, onDone) {
    wrapEl.style.display = '';
    skipEl.style.display = 'none';
    videoEl.src = settings.url;

    // Click-through layer
    if (settings.click_url && clickEl) {
      clickEl.href = settings.click_url;
      clickEl.style.display = '';
      clickEl.onclick = function () { trackAdEvent(position, 'click', cueIndex); };
    } else if (clickEl) {
      clickEl.style.display = 'none';
    }

    trackAdEvent(position, 'start', cueIndex);

    var skipAfter = settings.skip_after !== undefined ? settings.skip_after : 5;
    var elapsed = 0;
    var skipTimer = null;
    var done = false;

    function finish(eventName) {
      if (done) return;
      done = true;
      if (skipTimer) { clearInterval(skipTimer); skipTimer = null; }
      trackAdEvent(position, eventName, cueIndex);
      wrapEl.style.display = 'none';
      videoEl.pause();
      videoEl.src = '';
      if (countdownEl) countdownEl.textContent = '';
      if (clickEl) { clickEl.style.display = 'none'; clickEl.onclick = null; }
      videoEl.removeEventListener('loadeddata', onLoadedData);
      videoEl.removeEventListener('ended', onEnded);
      skipEl.removeEventListener('click', onSkip);
      onDone();
    }

    function onEnded() { finish('complete'); }
    function onSkip()  { finish('skip'); }

    function onLoadedData() {
      videoEl.play().catch(function () {});
      if (skipAfter === 0) {
        // Unskippable — no countdown, no skip button
        if (countdownEl) countdownEl.textContent = '';
      } else {
        if (countdownEl) countdownEl.textContent = 'Skip in ' + skipAfter + 's';
        skipTimer = setInterval(function () {
          elapsed++;
          var remaining = skipAfter - elapsed;
          if (remaining > 0) {
            if (countdownEl) countdownEl.textContent = 'Skip in ' + remaining + 's';
          } else {
            if (countdownEl) countdownEl.textContent = '';
            skipEl.style.display = '';
            clearInterval(skipTimer);
            skipTimer = null;
          }
        }, 1000);
      }
    }

    videoEl.addEventListener('loadeddata', onLoadedData);
    videoEl.addEventListener('ended', onEnded);
    skipEl.addEventListener('click', onSkip);
  }

  function playConfiguredAd(wrapEl, videoEl, skipEl, countdownEl, clickEl, slot, position, cueIndex, onDone) {
    resolveAdSlot(slot, function (resolved) {
      if (!resolved) {
        onDone();
        return;
      }

      playAdOverlay(wrapEl, videoEl, skipEl, countdownEl, clickEl, resolved, position, cueIndex, onDone);
    });
  }

  // -------------------------------------------------------------------------
  // Pre-roll ads
  // -------------------------------------------------------------------------
  function maybePreroll(settings, onDone) {
    var slot = getPrerollSlot(settings);
    if (!slot || prerollPlayed) { onDone(); return; }
    prerollPlaying = true;
    playConfiguredAd(
      prerollWrap, prerollVideo, prerollSkip, prerollCountdown, prerollClickLink,
      slot,
      'preroll', null,
      function () {
        prerollPlaying = false;
        prerollPlayed = true;
        onDone();
      }
    );
  }

  // -------------------------------------------------------------------------
  // Post-roll ads
  // -------------------------------------------------------------------------
  function bindPostroll(settings) {
    var slot = getPostrollSlot(settings);
    if (!slot) return;
    video.addEventListener('ended', function onVideoEnded() {
      video.removeEventListener('ended', onVideoEnded);
      if (postrollPlayed) return;
      postrollPlayed = true;
      playConfiguredAd(
        postrollWrap, postrollVideo, postrollSkip, postrollCountdown, postrollClickLink,
        slot,
        'postroll', null, function () {}
      );
    });
  }

  // -------------------------------------------------------------------------
  // Mid-roll cue points
  // -------------------------------------------------------------------------
  function setupMidroll(settings) {
    var cues = settings && settings.midroll_cues;
    if (!cues || !cues.length) return;
    cues = cues.slice();
    midrollFired = cues.map(function () { return false; });

    video.addEventListener('timeupdate', function () {
      if (!video.duration) return;
      cues.forEach(function (cue, i) {
        if (midrollFired[i]) return;
        var shouldTrigger = false;
        if (cue.trigger_kind === 'percent') {
          shouldTrigger = ((video.currentTime / video.duration) * 100) >= cue.trigger_value;
        } else {
          shouldTrigger = video.currentTime >= cue.trigger_value;
        }

        if (shouldTrigger) {
          midrollFired[i] = true;
          video.pause();
          playConfiguredAd(
            prerollWrap, prerollVideo, prerollSkip, prerollCountdown, prerollClickLink,
            getCueSlot(cue),
            'midroll', i,
            function () { video.play().catch(function () {}); }
          );
        }
      });
    });
  }

  // -------------------------------------------------------------------------
  // Continue watching (localStorage)
  // -------------------------------------------------------------------------
  var STORAGE_KEY = 'vp_resume_';

  function getSavedPosition(uuid) {
    try {
      return parseFloat(localStorage.getItem(STORAGE_KEY + uuid)) || 0;
    } catch (e) { return 0; }
  }

  function savePosition(uuid, time) {
    try {
      localStorage.setItem(STORAGE_KEY + uuid, String(Math.floor(time)));
    } catch (e) {}
  }

  function clearSavedPosition(uuid) {
    try {
      localStorage.removeItem(STORAGE_KEY + uuid);
    } catch (e) {}
  }

  function showResumeToast(seconds, onResume, onDismiss) {
    resumeText.textContent = 'Resume from ' + formatTime(seconds) + '?';
    resumeToast.style.display = '';

    var done = false;
    resumeYes.onclick = function () {
      if (done) return; done = true;
      resumeToast.style.display = 'none';
      onResume();
    };
    resumeNo.onclick = function () {
      if (done) return; done = true;
      resumeToast.style.display = 'none';
      onDismiss();
    };
  }

  // -------------------------------------------------------------------------
  // State screens
  // -------------------------------------------------------------------------
  function showPending() {
    pendingScreen.style.display = '';
    errorScreen.style.display = 'none';
    hideAdblockOverlay();
  }

  function hidePending() {
    pendingScreen.style.display = 'none';
  }

  function showError(msg) {
    errorMsg.textContent = msg || 'An error occurred.';
    errorScreen.style.display = '';
    pendingScreen.style.display = 'none';
    hideAdblockOverlay();
  }

  function showOriginalBanner() {
    origBanner.style.display = '';
  }

  // -------------------------------------------------------------------------
  // Polling (for pending/original states)
  // -------------------------------------------------------------------------
  function schedulePoll(ms) {
    clearPoll();
    if (!ms) return;
    pollTimer = setInterval(function () {
      if (cfg.bootstrapUrl) {
        fetchBootstrap(cfg.bootstrapUrl);
      }
    }, ms);
  }

  function clearPoll() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  // -------------------------------------------------------------------------
  // Event bindings
  // -------------------------------------------------------------------------
  function bindEvents() {
    // Big play button / poster click
    bigPlay.addEventListener('click', handleFirstPlay);
    poster.addEventListener('click', handleFirstPlay);

    // Play/pause
    playBtn.addEventListener('click', togglePlayPause);

    // Mute
    muteBtn.addEventListener('click', function () {
      video.muted = !video.muted;
    });

    // Volume
    volumeSlider.addEventListener('input', function () {
      video.volume = this.value / 100;
      video.muted = this.value === '0';
    });

    // Progress seeking
    progressInput.addEventListener('input', function () {
      isSeeking = true;
      var pct = this.value / 1000;
      progressPlayed.style.width = (pct * 100) + '%';
    });
    progressInput.addEventListener('change', function () {
      if (video.duration) {
        video.currentTime = (this.value / 1000) * video.duration;
      }
      isSeeking = false;
    });

    // Video events
    video.addEventListener('timeupdate', onTimeUpdate);
    video.addEventListener('progress', onProgress);
    video.addEventListener('play', function () {
      container.classList.add('vp-playing');
      iconPlay.style.display = 'none';
      iconPause.style.display = '';
      postMessage('player.play');
    });
    video.addEventListener('pause', function () {
      container.classList.remove('vp-playing');
      iconPlay.style.display = '';
      iconPause.style.display = 'none';
      postMessage('player.pause');
    });
    video.addEventListener('ended', function () {
      container.classList.remove('vp-playing');
      if (bootstrapData) clearSavedPosition(bootstrapData.video_uuid);
      postMessage('player.ended');
    });
    video.addEventListener('volumechange', function () {
      iconVol.style.display = video.muted ? 'none' : '';
      iconMuted.style.display = video.muted ? '' : 'none';
      if (!video.muted) volumeSlider.value = video.volume * 100;
    });
    video.addEventListener('error', function () {
      showError('Playback error.');
      postMessage('player.error');
    });

    // Fullscreen
    fsBtn.addEventListener('click', toggleFullscreen);
    document.addEventListener('fullscreenchange', updateFsIcon);
    document.addEventListener('webkitfullscreenchange', updateFsIcon);

    // Dropdown toggles
    qualityBtn.addEventListener('click', function (e) { e.stopPropagation(); toggleMenu(qualityMenu); });
    speedBtn.addEventListener('click', function (e) { e.stopPropagation(); toggleMenu(speedMenu); });
    captionBtn.addEventListener('click', function (e) { e.stopPropagation(); toggleMenu(captionMenu); });
    document.addEventListener('click', closeMenus);

    // Controls auto-hide
    container.addEventListener('mousemove', showControlsTemporarily);
    container.addEventListener('touchstart', showControlsTemporarily);

    // Keyboard shortcuts
    document.addEventListener('keydown', onKeyDown);

    if (adblockRetry) {
      adblockRetry.addEventListener('click', function () {
        hideAdblockOverlay();
        handleFirstPlay();
      });
    }
    if (directFrameClose) {
      directFrameClose.addEventListener('click', closeDirectFrame);
    }

    // Inbound postMessage (for embed mode)
    if (cfg.mode === 'embed') {
      window.addEventListener('message', onParentMessage);
    }
  }

  function handleFirstPlay() {
    poster.style.display = 'none';

    if (!bootstrapData || bootstrapData.playback_mode !== 'hls') {
      video.play().catch(function () {});
      return;
    }

    if (firstPlayGateDone) {
      playMainContent();
      return;
    }

    if (firstPlayGateRunning) return;

    firstPlayGateRunning = true;
    runFirstPlayGate(
      function () {
        firstPlayGateRunning = false;
        firstPlayGateDone = true;
        playMainContent();
      },
      function () {
        firstPlayGateRunning = false;
      }
    );
  }

  function ensureFirstPlayBeforeResume(onDone) {
    if (firstPlayGateDone) {
      onDone();
      return;
    }

    if (firstPlayGateRunning) return;
    firstPlayGateRunning = true;
    runFirstPlayGate(
      function () {
        firstPlayGateRunning = false;
        firstPlayGateDone = true;
        onDone();
      },
      function () {
        firstPlayGateRunning = false;
      }
    );
  }

  // -------------------------------------------------------------------------
  // Transport controls
  // -------------------------------------------------------------------------
  function togglePlayPause() {
    if (prerollPlaying) return;
    if (video.paused) {
      if (bootstrapData && bootstrapData.playback_mode === 'hls' && !firstPlayGateDone) {
        handleFirstPlay();
      } else {
        video.play().catch(function () {});
      }
    } else {
      video.pause();
    }
  }

  function toggleFullscreen() {
    var el = container;
    try {
      if (document.fullscreenElement || document.webkitFullscreenElement) {
        var exitFn = document.exitFullscreen || document.webkitExitFullscreen;
        if (typeof exitFn === 'function') exitFn.call(document);
      } else {
        var reqFn = el.requestFullscreen || el.webkitRequestFullscreen;
        if (typeof reqFn === 'function') reqFn.call(el);
      }
    } catch (e) {
      // Fullscreen API unavailable or call failed — ignore
    }
  }

  function updateFsIcon() {
    var isFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
    iconFs.style.display = isFs ? 'none' : '';
    iconFsExit.style.display = isFs ? '' : 'none';
  }

  // -------------------------------------------------------------------------
  // Time / progress
  // -------------------------------------------------------------------------
  function onTimeUpdate() {
    if (isSeeking || !video.duration) return;

    var pct = video.currentTime / video.duration;
    progressPlayed.style.width = (pct * 100) + '%';
    progressInput.value = Math.round(pct * 1000);
    timeDisplay.textContent = formatTime(video.currentTime) + ' / ' + formatTime(video.duration);

    // Save position periodically
    if (bootstrapData && video.currentTime > 5) {
      savePosition(bootstrapData.video_uuid, video.currentTime);
    }
  }

  function onProgress() {
    if (!video.duration || !video.buffered.length) return;
    var end = video.buffered.end(video.buffered.length - 1);
    progressBuffered.style.width = ((end / video.duration) * 100) + '%';
  }

  function formatTime(secs) {
    secs = Math.floor(secs);
    var h = Math.floor(secs / 3600);
    var m = Math.floor((secs % 3600) / 60);
    var s = secs % 60;
    if (h > 0) return h + ':' + pad(m) + ':' + pad(s);
    return m + ':' + pad(s);
  }

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  // -------------------------------------------------------------------------
  // Controls visibility
  // -------------------------------------------------------------------------
  function showControlsTemporarily() {
    container.classList.add('vp-controls-visible');
    clearTimeout(controlsTimer);
    controlsTimer = setTimeout(function () {
      if (!video.paused) container.classList.remove('vp-controls-visible');
    }, 3000);
  }

  // -------------------------------------------------------------------------
  // Keyboard shortcuts
  // -------------------------------------------------------------------------
  function onKeyDown(e) {
    // Don't capture if user is typing in an input
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

    switch (e.key) {
      case ' ':
      case 'k':
        e.preventDefault();
        togglePlayPause();
        break;
      case 'f':
        e.preventDefault();
        toggleFullscreen();
        break;
      case 'm':
        e.preventDefault();
        video.muted = !video.muted;
        break;
      case 'ArrowLeft':
        e.preventDefault();
        video.currentTime = Math.max(0, video.currentTime - 10);
        break;
      case 'ArrowRight':
        e.preventDefault();
        video.currentTime = Math.min(video.duration || 0, video.currentTime + 10);
        break;
      case 'ArrowUp':
        e.preventDefault();
        video.volume = Math.min(1, video.volume + 0.1);
        break;
      case 'ArrowDown':
        e.preventDefault();
        video.volume = Math.max(0, video.volume - 0.1);
        break;
    }
  }

  // -------------------------------------------------------------------------
  // Anti-download
  // -------------------------------------------------------------------------
  function preventContextMenu() {
    container.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  }

  // -------------------------------------------------------------------------
  // postMessage (embed mode)
  // -------------------------------------------------------------------------
  function postMessage(type, data) {
    if (cfg.mode !== 'embed' || !cfg.parentOrigin) return;
    try {
      window.parent.postMessage({ type: type, data: data || {} }, cfg.parentOrigin);
    } catch (e) {}
  }

  function onParentMessage(e) {
    if (!cfg.parentOrigin || e.origin !== cfg.parentOrigin) return;
    var msg = e.data;
    if (!msg || !msg.type) return;

    switch (msg.type) {
      case 'player.play':
        if (bootstrapData && bootstrapData.playback_mode === 'hls') {
          ensureFirstPlayBeforeResume(function () {
            playMainContent();
          });
        } else {
          video.play().catch(function () {});
        }
        break;
      case 'player.pause':
        video.pause();
        break;
      case 'player.seek':
        if (typeof msg.data === 'number') video.currentTime = msg.data;
        break;
      case 'player.setMuted':
        video.muted = !!msg.data;
        break;
    }
  }

  // -------------------------------------------------------------------------
  // Boot
  // -------------------------------------------------------------------------
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
