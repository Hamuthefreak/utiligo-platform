/**
 * onboarding-purchase.js
 * Per-plan purchase success animation.
 * Reads data-ob-plan from <body> to pick colour/copy/particles.
 */
(function () {
  'use strict';

  const plan = (document.body.dataset.obPlan || 'free').toLowerCase();
  const SESSION_KEY = 'utl_purchase_ob_' + plan;

  if (!sessionStorage.getItem(SESSION_KEY)) return;
  sessionStorage.removeItem(SESSION_KEY);

  /* ── Plan config ────────────────────────────────────────────── */
  const CFG = {
    free: {
      bgCls:     'ob-plan-free-bg',
      iconCls:   'ob-icon-free',
      icon:      '🌱',
      badgeCls:  'ob-badge-free',
      badge:     'Free Plan Activated',
      title:     "You're in!",
      sub:       "Your free account is ready. Start finding clients today.",
      perks:     [
        { icon: '🔍', text: '3 lead searches today' },
        { icon: '⚡',   text: '1 site to build right now' },
        { icon: '📊', text: 'Revenue dashboard unlocked' },
      ],
      goCls:    '',
      goLabel:  'Go to Dashboard →',
      particle: 'stars',
      color:    '#10b981',
    },
    pro: {
      bgCls:     'ob-plan-pro-bg',
      iconCls:   'ob-icon-pro',
      icon:      '👑',
      badgeCls:  'ob-badge-pro',
      badge:     'Pro Plan Unlocked',
      title:     "Pro mode: ON.",
      sub:       "700 leads, unlimited generates, all templates. Let's go.",
      perks:     [
        { icon: '👑', text: '700 leads per search' },
        { icon: '⚡',   text: 'Unlimited daily site generates' },
        { icon: '🎨', text: 'All premium templates' },
        { icon: '🏠', text: '50 hosted websites' },
      ],
      goCls:    'ob-go-pro',
      goLabel:  'Open Dashboard →',
      particle: 'shooting',
      color:    '#8b5cf6',
    },
    entrepreneur: {
      bgCls:     'ob-plan-ent-bg',
      iconCls:   'ob-icon-ent',
      icon:      '🚀',
      badgeCls:  'ob-badge-ent',
      badge:     'Entrepreneur Plan Active',
      title:     "You\'re scaling now.",
      sub:       "Unlimited leads, 5 team seats, custom domains — the full stack.",
      perks:     [
        { icon: '♾️',  text: 'Unlimited leads' },
        { icon: '👥', text: '5 team member seats' },
        { icon: '🌐', text: 'Custom domains' },
        { icon: '🏢', text: 'White-label branding' },
        { icon: '🚀', text: '500 hosted sites' },
      ],
      goCls:    'ob-go-ent',
      goLabel:  'Launch Dashboard →',
      particle: 'confetti',
      color:    '#f59e0b',
    },
  };

  const c = CFG[plan] || CFG.free;

  /* ── Build overlay ─────────────────────────────────────────── */
  const overlay = document.createElement('div');
  overlay.id        = 'ob-purchase';
  overlay.className = `ob-overlay ${c.bgCls}`;

  const perksHtml = c.perks.map((p, i) => `
    <div class="ob-perk" data-delay="${1100 + i * 120}">
      <span class="ob-perk-icon">${p.icon}</span>
      <span>${_esc(p.text)}</span>
    </div>
  `).join('');

  overlay.innerHTML = `
    <canvas class="ob-canvas" id="ob-purchase-canvas"></canvas>
    <div class="ob-purchase-inner">
      <div class="ob-purchase-icon ${c.iconCls}">${c.icon}</div>
      <div class="ob-purchase-plan-badge ${c.badgeCls}">${_esc(c.badge)}</div>
      <h1 class="ob-purchase-title">${_esc(c.title)}</h1>
      <p class="ob-purchase-sub">${_esc(c.sub)}</p>
      <div class="ob-purchase-perks">${perksHtml}</div>
      <button class="ob-purchase-go ${c.goCls}" id="ob-purchase-btn">${_esc(c.goLabel)}</button>
    </div>
  `;
  document.body.appendChild(overlay);
  document.body.style.overflow = 'hidden';

  /* ── Stagger perks ──────────────────────────────────────────── */
  overlay.querySelectorAll('.ob-perk[data-delay]').forEach(el => {
    setTimeout(() => el.classList.add('ob-in'), +el.dataset.delay);
  });

  /* ── Particle effect ────────────────────────────────────────── */
  const canvas = document.getElementById('ob-purchase-canvas');
  if      (c.particle === 'stars')    _particleStars(canvas, c.color);
  else if (c.particle === 'shooting') _particleShooting(canvas, c.color);
  else if (c.particle === 'confetti') _particleConfetti(canvas);

  /* ── Dismiss ───────────────────────────────────────────────── */
  let dismissed = false;
  const dismiss = () => {
    if (dismissed) return;
    dismissed = true;
    overlay.classList.add('ob-exit');
    document.body.style.overflow = '';
    setTimeout(() => overlay.remove(), 600);
  };
  document.getElementById('ob-purchase-btn').addEventListener('click', dismiss);
  setTimeout(dismiss, 5000);

  /* ================================================================
     PARTICLE ENGINES
     ============================================================== */

  // FREE — floating twinkle stars
  function _particleStars(canvas, color) {
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let W, H;
    const resize = () => { W = canvas.width = canvas.offsetWidth; H = canvas.height = canvas.offsetHeight; };
    resize(); window.addEventListener('resize', resize);
    const stars = Array.from({length: 60}, () => ({
      x:  Math.random() * (W||800), y: Math.random() * (H||600),
      r:  Math.random() * 2 + 0.5,
      a:  Math.random() * Math.PI * 2,
      da: (Math.random() - 0.5) * 0.025,
      vy: -(Math.random() * 0.4 + 0.1),
      pulse: Math.random() * Math.PI * 2,
    }));
    let alive = true;
    overlay.addEventListener('transitionend', () => { alive = false; });
    const frame = () => {
      if (!alive) return;
      ctx.clearRect(0, 0, W, H);
      stars.forEach(s => {
        s.y += s.vy;
        s.a += s.da;
        s.pulse += 0.05;
        if (s.y < -5) { s.y = H + 5; s.x = Math.random() * W; }
        const alpha = 0.2 + 0.5 * Math.abs(Math.sin(s.pulse));
        ctx.save();
        ctx.translate(s.x, s.y);
        ctx.rotate(s.a);
        // 4-pointed star shape
        ctx.beginPath();
        for (let i = 0; i < 4; i++) {
          const angle = (i / 4) * Math.PI * 2;
          const ir = s.r * 0.4, or = s.r * 1.4;
          ctx.lineTo(Math.cos(angle) * or, Math.sin(angle) * or);
          ctx.lineTo(Math.cos(angle + Math.PI/4) * ir, Math.sin(angle + Math.PI/4) * ir);
        }
        ctx.closePath();
        ctx.fillStyle = color + Math.floor(alpha * 255).toString(16).padStart(2,'0');
        ctx.fill();
        ctx.restore();
      });
      requestAnimationFrame(frame);
    };
    frame();
  }

  // PRO — shooting star lines
  function _particleShooting(canvas, color) {
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let W, H;
    const resize = () => { W = canvas.width = canvas.offsetWidth; H = canvas.height = canvas.offsetHeight; };
    resize(); window.addEventListener('resize', resize);

    const mkShooter = () => ({
      x:  Math.random() * (W||800),
      y:  Math.random() * ((H||600) * 0.5),
      len: Math.random() * 80 + 40,
      spd: Math.random() * 5 + 3,
      ang: Math.PI / 4 + (Math.random() - 0.5) * 0.3,
      a:  Math.random(),
      dead: false,
    });

    let shooters = Array.from({length: 8}, mkShooter);
    let alive = true;
    overlay.addEventListener('transitionend', () => { alive = false; });

    // Background slow particles too
    const dust = Array.from({length: 30}, () => ({
      x: Math.random()*(W||800), y: Math.random()*(H||600),
      r: Math.random()*1.2+0.3, vy:-(Math.random()*0.3+0.05), a: Math.random(),
    }));

    const frame = () => {
      if (!alive) return;
      ctx.clearRect(0, 0, W, H);

      // dust
      dust.forEach(d => {
        d.y += d.vy; d.a += 0.008;
        if (d.y < -5) { d.y = H + 5; d.x = Math.random()*W; }
        ctx.beginPath(); ctx.arc(d.x, d.y, d.r, 0, Math.PI*2);
        ctx.fillStyle = color + Math.floor((0.1+0.15*Math.abs(Math.sin(d.a)))*255).toString(16).padStart(2,'0');
        ctx.fill();
      });

      // shooters
      shooters.forEach((s, idx) => {
        const dx = Math.cos(s.ang) * s.spd;
        const dy = Math.sin(s.ang) * s.spd;
        s.x += dx; s.y += dy;
        if (s.x > W + 50 || s.y > H + 50) { shooters[idx] = mkShooter(); return; }
        const tail_x = s.x - Math.cos(s.ang) * s.len;
        const tail_y = s.y - Math.sin(s.ang) * s.len;
        const grad = ctx.createLinearGradient(tail_x, tail_y, s.x, s.y);
        grad.addColorStop(0, color + '00');
        grad.addColorStop(1, color + 'cc');
        ctx.beginPath();
        ctx.moveTo(tail_x, tail_y);
        ctx.lineTo(s.x, s.y);
        ctx.strokeStyle = grad;
        ctx.lineWidth = 1.5;
        ctx.stroke();
        // head dot
        ctx.beginPath(); ctx.arc(s.x, s.y, 2, 0, Math.PI*2);
        ctx.fillStyle = color;
        ctx.fill();
      });

      requestAnimationFrame(frame);
    };
    frame();
  }

  // ENTREPRENEUR — confetti explosion
  function _particleConfetti(canvas) {
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let W, H;
    const resize = () => { W = canvas.width = canvas.offsetWidth; H = canvas.height = canvas.offsetHeight; };
    resize(); window.addEventListener('resize', resize);

    const COLORS = ['#f59e0b','#fbbf24','#fcd34d','#f97316','#fb923c',
                    '#a78bfa','#8b5cf6','#c4b5fd','#10b981','#34d399','#fff'];
    const mkConfetti = () => {
      const cx = W ? W/2 : 400, cy = H ? H/2 : 300;
      const angle = Math.random() * Math.PI * 2;
      const speed = Math.random() * 8 + 3;
      return {
        x:  cx + (Math.random()-0.5)*80,
        y:  cy + (Math.random()-0.5)*80,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed - (Math.random()*4+2),
        w:  Math.random()*8+3,
        h:  Math.random()*4+2,
        rot: Math.random()*Math.PI*2,
        drot: (Math.random()-0.5)*0.18,
        col: COLORS[Math.floor(Math.random()*COLORS.length)],
        life: 1,
        decay: Math.random()*0.008+0.004,
        gravity: Math.random()*0.18+0.08,
      };
    };

    let pieces = [];
    // Burst on start
    setTimeout(() => {
      pieces = Array.from({length: 120}, mkConfetti);
    }, 200);
    // Second burst
    setTimeout(() => {
      pieces = pieces.concat(Array.from({length: 80}, mkConfetti));
    }, 700);

    let alive = true;
    overlay.addEventListener('transitionend', () => { alive = false; });

    const frame = () => {
      if (!alive) return;
      ctx.clearRect(0, 0, W, H);
      pieces = pieces.filter(p => p.life > 0);
      pieces.forEach(p => {
        p.x  += p.vx;
        p.y  += p.vy;
        p.vy += p.gravity;
        p.vx *= 0.99;
        p.rot += p.drot;
        p.life -= p.decay;
        ctx.save();
        ctx.globalAlpha = Math.max(0, p.life);
        ctx.translate(p.x, p.y);
        ctx.rotate(p.rot);
        ctx.fillStyle = p.col;
        ctx.fillRect(-p.w/2, -p.h/2, p.w, p.h);
        ctx.restore();
      });
      requestAnimationFrame(frame);
    };
    frame();
  }

  /* ── Helpers ──────────────────────────────────────────────── */
  function _esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
}());
