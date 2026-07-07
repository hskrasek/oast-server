{{-- Self-contained 1200×630 review OG card. All data comes from OgTemplate;
     no @php, no @vite — the payload is sent verbatim to Cloudflare screenshot. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
{!! $fonts !!}
:root{--surface:#171310;--ink:#ede4d8;--muted:#a89a89;--subtle:#8f7f6c;--ember:#f26430;--amber:#dda032;--serif:'Newsreader',Georgia,serif;--mono:'IBM Plex Mono',ui-monospace,monospace;}
*{margin:0;box-sizing:border-box;}
.og{position:relative;width:1200px;height:630px;overflow:hidden;background:var(--surface);display:flex;flex-direction:column;justify-content:space-between;padding:56px 64px 52px;}
.og::before{content:"";position:absolute;inset:0;background:radial-gradient(ellipse 70% 100% at 50% 100%,rgba(242,100,48,.13),transparent 65%);}
.og::after{content:"";position:absolute;left:0;right:0;bottom:0;height:2px;background:linear-gradient(90deg,transparent,rgba(242,100,48,.55),transparent);}
.og>*{position:relative;}
.top{display:flex;align-items:baseline;justify-content:space-between;}
.wordmark{font:600 26px/1 var(--mono);color:var(--ink);}
.wordmark em{font-style:normal;color:var(--ember);}
.kicker{font:500 15px/1 var(--mono);letter-spacing:.14em;text-transform:uppercase;color:var(--muted);}
.mid{display:flex;flex-direction:column;gap:22px;max-width:980px;}
.headline{font:400 64px/1.12 var(--serif);color:var(--ink);text-wrap:balance;}
.spec{font:500 21px/1 var(--mono);color:var(--muted);}
.bottom{display:flex;align-items:center;justify-content:space-between;gap:32px;}
.tally{display:flex;align-items:center;gap:36px;}
.sev{display:inline-flex;align-items:center;gap:12px;font:600 18px/1 var(--mono);text-transform:uppercase;letter-spacing:.02em;}
.sev::before{content:"";width:13px;height:13px;border-radius:3px;background:currentColor;}
.sev-blocker{color:var(--ember);}
.sev-should-fix{color:var(--amber);}
.sev-consider{color:var(--subtle);}
.cost{font:600 20px/1 var(--mono);color:var(--ember);white-space:nowrap;}
</style>
</head>
<body>
<div class="og">
  <div class="top">
    <span class="wordmark">oast<em>.sh</em></span>
    <span class="kicker">{{ $kicker }}</span>
  </div>
  <div class="mid">
    <div class="headline">{{ $headline }}</div>
    <div class="spec">{{ $specName }}</div>
  </div>
  <div class="bottom">
    <div class="tally">
      @foreach ($tally as $class => $label)
      <span class="sev {{ $class }}">{{ $label }}</span>
      @endforeach
    </div>
    @if ($cost !== null)
    <span class="cost">${{ number_format($cost, 2) }}</span>
    @endif
  </div>
</div>
</body>
</html>
