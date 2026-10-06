<?php
if ($db) {
    $db->select_db('tymos');

    $db->query("CREATE TABLE IF NOT EXISTS waste_calendar (
        dt   DATE NOT NULL,
        type VARCHAR(20) NOT NULL,
        PRIMARY KEY (dt, type)
    )");

    // import domyślnego harmonogramu przy pierwszym uruchomieniu
    $cnt = $db->query("SELECT COUNT(*) AS c FROM waste_calendar")->fetch_assoc()['c'];
    if ((int)$cnt === 0) {
        $defaults = [
            '2026-02-04'=>'plastik',  '2026-02-05'=>'zmieszane','2026-02-06'=>'bio',
            '2026-02-09'=>'popiol',   '2026-02-17'=>'papier',   '2026-02-18'=>'plastik',
            '2026-02-19'=>'zmieszane','2026-02-20'=>'szklo',    '2026-02-23'=>'popiol',
            '2026-02-27'=>'tekstylia',
            '2026-03-05'=>'zmieszane','2026-03-06'=>'bio',      '2026-03-09'=>'popiol',
            '2026-03-10'=>'plastik',  '2026-03-17'=>'papier',   '2026-03-18'=>'wielkogabaryty',
            '2026-03-19'=>'zmieszane','2026-03-20'=>'szklo',    '2026-03-23'=>'popiol',
            '2026-04-02'=>'zmieszane','2026-04-03'=>'bio',      '2026-04-07'=>'plastik',
            '2026-04-14'=>'popiol',   '2026-04-15'=>'papier',   '2026-04-16'=>'zmieszane',
            '2026-04-17'=>'bio',      '2026-04-20'=>'szklo',    '2026-04-21'=>'plastik',
            '2026-04-29'=>'bio',      '2026-04-30'=>'zmieszane',
            '2026-05-05'=>'plastik',  '2026-05-12'=>'bio',      '2026-05-14'=>'zmieszane',
            '2026-05-15'=>'popiol',   '2026-05-18'=>'papier',   '2026-05-19'=>'plastik',
            '2026-05-26'=>'bio',      '2026-05-27'=>'szklo',    '2026-05-28'=>'zmieszane',
            '2026-06-01'=>'popiol',   '2026-06-09'=>'bio',      '2026-06-11'=>'zmieszane',
            '2026-06-12'=>'plastik',  '2026-06-18'=>'tekstylia','2026-06-23'=>'bio',
            '2026-06-25'=>'zmieszane','2026-06-26'=>'papier',   '2026-06-29'=>'szklo',
        ];
        foreach ($defaults as $dt => $type) {
            $db->query("INSERT IGNORE INTO waste_calendar (dt,type) VALUES ('$dt','$type')");
        }
    }

    $res      = $db->query("SELECT DATE_FORMAT(dt,'%Y-%m-%d') AS dt, type FROM waste_calendar ORDER BY dt, type");
    $schedule = [];
    while ($row = $res->fetch_assoc()) $schedule[$row['dt']][] = $row['type'];

    $db->select_db('tymos');
} else {
    $schedule = [];
}
?>
<style>
.smc-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.smc-title{font-size:11px;font-weight:600;letter-spacing:1px;color:var(--text-dim)}
.smc-nav{background:#333;border:none;color:#aaa;font-size:16px;cursor:pointer;padding:4px 12px;border-radius:6px;-webkit-tap-highlight-color:transparent}
.smc-nav:hover{background:#444;color:#fff}
.smc-wdays{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:2px}
.smc-wdays span{text-align:center;font-size:9px;color:#888;padding:2px 0;text-transform:uppercase}
.smc-wdays span:nth-child(6),.smc-wdays span:nth-child(7){color:#aa4444}
.smc-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.smc-day{position:relative;height:27px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:200;color:#b0b0b0;border-radius:3px;background:#2a2a2a;cursor:pointer;-webkit-tap-highlight-color:transparent;user-select:none}
.smc-mark{position:absolute;top:3px;right:3px;width:7px;height:7px;border-radius:50%}
.smc-ico{position:absolute;top:1px;right:3px;font-size:11px;line-height:1;pointer-events:none}
.smc-day:active{transform:scale(0.93)}
.smc-empty{background:transparent!important;cursor:default!important}
.smc-empty:active{transform:none!important}
.smc-weekend{color:#e05252}   /* sob/nd wyrozniaja sie same — naglowek dni tygodnia zdjety */
.smc-today{outline:2px solid #eee;outline-offset:-2px;font-weight:700}
.smc-col{color:#aaa;font-weight:200;text-shadow:0 1px 2px rgba(0,0,0,.5)}
.smc-overlay{display:none;position:fixed;inset:0;z-index:90}
.smc-overlay.show{display:block}
.smc-picker{display:none;position:fixed;background:#252525;border-radius:14px;padding:8px 10px;box-shadow:0 6px 32px rgba(0,0,0,.7);border:1px solid #444;z-index:100;gap:2px;top:0;left:0}
.smc-picker.show{display:grid;grid-template-columns:1fr 1fr}
.smc-prow{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;cursor:pointer;-webkit-tap-highlight-color:transparent}
.smc-prow:hover,.smc-prow:active{background:#333}
.smc-prow.smc-active{background:#333;box-shadow:inset 3px 0 0 #4caf50}
.smc-prow.smc-active .smc-plbl{color:#fff}
.smc-dot{width:18px;height:18px;border-radius:5px;flex-shrink:0}
.smc-plbl{font-size:14px;color:#bbb}
.smc-prow:hover .smc-plbl{color:#fff}
.smc-pclear{border-top:1px solid #333;margin-top:1px;grid-column:1/-1}
.smc-pclear .smc-plbl{color:#666}
.smc-pclear:hover .smc-plbl{color:#aaa}
.smc-saved{position:fixed;top:10px;right:10px;background:#4caf50;color:#fff;padding:6px 12px;border-radius:6px;font-size:12px;opacity:0;transition:opacity .3s;z-index:200;pointer-events:none}
.smc-saved.show{opacity:1}
</style>

<div class="smc-hdr">
  <button class="smc-nav" id="smc-prev">‹</button>
  <span class="smc-title" id="smc-month"></span>
  <button class="smc-nav" id="smc-next">›</button>
</div>
<div class="smc-grid" id="smc-grid"></div>

<div class="smc-overlay" id="smc-overlay"></div>
<div class="smc-picker" id="smc-picker"></div>
<div class="smc-saved" id="smc-saved">Zapisano</div>

<script>
(function() {
  var TYPES = {
    plastik:        { color:'#c8a630', label:'Plastik',       dark:true  },
    papier:         { color:'#3388cc', label:'Papier'                     },
    szklo:          { color:'#388e5c', label:'Szkło'                      },
    zmieszane:      { color:'#000000', label:'Zmieszane'                  },
    bio:            { color:'#6d4c2a', label:'Bio'                        },
    wielkogabaryty: { color:'#8e6aad', label:'Gabaryty'                   },
    tekstylia:      { color:'#c07028', label:'Tekstylia'                  },
    opony:          { color:'#b04040', label:'Opony'                      },
    popiol:         { color:'#888888', label:'Popiół',         dark:true  },
    wodomierz:      { color:'#4aa3ff', label:'Wodomierz',      dot:true, icon:'💧' },
  };

  var MONTHS = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec',
                'Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];

  var schedule     = <?= json_encode($schedule) ?>;
  var pickerDay    = null;
  var now          = new Date();
  var curYear      = now.getFullYear();
  var curMonth     = now.getMonth() + 1;
  var navigatedAt  = 0;

  // --- picker build (rebuild on show with active state) ---
  function buildPicker(activeTypes) {
    var ph = '';
    for (var id in TYPES) {
      var act = activeTypes.indexOf(id) !== -1 ? ' smc-active' : '';
      var swatch = TYPES[id].icon
          ? '<div class="smc-dot" style="display:flex;align-items:center;justify-content:center;font-size:14px">'+TYPES[id].icon+'</div>'
          : '<div class="smc-dot" style="background:'+TYPES[id].color+'"></div>';
      ph += '<div class="smc-prow'+act+'" data-type="'+id+'">'
          + swatch
          + '<span class="smc-plbl">'+TYPES[id].label+'</span></div>';
    }
    ph += '<div class="smc-prow smc-pclear" data-type="">'
        + '<div class="smc-dot" style="background:#1c1c1c"></div>'
        + '<span class="smc-plbl">Wyczyść</span></div>';
    $('#smc-picker').html(ph);
  }

  // --- render ---
  function render() {
    var key    = curYear + '-' + String(curMonth).padStart(2,'0');
    var today  = new Date();
    var isCur  = today.getFullYear()===curYear && (today.getMonth()+1)===curMonth;
    var todayD = today.getDate();
    var first  = new Date(curYear, curMonth-1, 1).getDay();
    var off    = first===0 ? 6 : first-1;
    var days   = new Date(curYear, curMonth, 0).getDate();

    $('#smc-month').text(MONTHS[curMonth-1] + ' ' + curYear);

    var h = '';
    for (var i=0; i<off; i++) h += '<div class="smc-day smc-empty"></div>';
    for (var d=1; d<=days; d++) {
      var dow     = (off+d-1) % 7;
      var dt      = key + '-' + String(d).padStart(2,'0');
      var types   = schedule[dt] || [];
      var cls     = 'smc-day';
      var sty     = '';
      var dotsHtml = '';

      // fill: pierwszy non-dot type
      var fillInfo = null;
      for (var ti=0; ti<types.length; ti++) {
        var info = TYPES[types[ti]];
        if (info && !info.dot) { fillInfo = info; break; }
      }
      if (fillInfo) {
        cls += ' smc-col';
        sty = 'background:'+fillInfo.color+';'+(fillInfo.dark?'color:#333;text-shadow:none;':'');
      }

      // kropki: wszystkie dot:true types, kaskadowo w prawym gornym rogu
      var dotOff = 3;
      for (var ti=0; ti<types.length; ti++) {
        var info = TYPES[types[ti]];
        if (info && info.dot) {
          if (info.icon) {
            dotsHtml += '<span class="smc-ico" style="right:'+(dotOff-1)+'px">'+info.icon+'</span>';
            dotOff += 13;
          } else {
            dotsHtml += '<span class="smc-mark" style="background:'+info.color+';right:'+dotOff+'px"></span>';
            dotOff += 9;
          }
        }
      }

      if (dow>=5)              cls += ' smc-weekend';
      if (isCur && d===todayD) cls += ' smc-today';

      h += '<div class="'+cls+'" style="'+sty+'" data-d="'+d+'">'+dotsHtml+d+'</div>';
    }
    $('#smc-grid').html(h);
  }

  // --- picker show/hide ---
  function showPicker(d, el) {
    pickerDay = d;
    var dt = curYear + '-' + String(curMonth).padStart(2,'0') + '-' + String(d).padStart(2,'0');
    buildPicker(schedule[dt] || []);
    var pk = document.getElementById('smc-picker');
    pk.style.top  = '0';
    pk.style.left = '0';
    $('#smc-picker, #smc-overlay').addClass('show');
    // pozycjonuj po show() żeby mieć wymiary
    var rect   = el.getBoundingClientRect();
    var pw     = pk.offsetWidth;
    var ph     = pk.offsetHeight;
    var margin = 8;
    var left   = rect.left + rect.width / 2 - pw / 2;
    left = Math.max(margin, Math.min(left, window.innerWidth - pw - margin));
    var top = rect.bottom + margin;
    if (top + ph > window.innerHeight - margin) top = rect.top - ph - margin;
    top = Math.max(margin, top);
    pk.style.top  = top  + 'px';
    pk.style.left = left + 'px';
  }
  function hidePicker() {
    pickerDay = null;
    $('#smc-picker, #smc-overlay').removeClass('show');
  }

  // --- save ---
  function save(dt, type, op) {
    $.post('api.php', { action:'smieci_save', dt:dt, type:type, op:op||'' }, function(r) {
      if (r.ok) {
        $('#smc-saved').addClass('show');
        setTimeout(function(){ $('#smc-saved').removeClass('show'); }, 1200);
      }
    });
  }

  // --- events ---
  $('#smc-prev').on('click', function() {
    navigatedAt = Date.now();
    curMonth--; if (curMonth<1) { curMonth=12; curYear--; } render();
  });
  $('#smc-next').on('click', function() {
    navigatedAt = Date.now();
    curMonth++; if (curMonth>12) { curMonth=1; curYear++; } render();
  });
  $('#smc-grid').on('click', '.smc-day:not(.smc-empty)', function() {
    showPicker(+$(this).data('d'), this);
  });
  $('#smc-overlay').on('click', hidePicker);
  $('#smc-picker').on('click', '.smc-prow', function() {
    if (pickerDay===null) return;
    var type = $(this).data('type');
    var dt   = curYear + '-' + String(curMonth).padStart(2,'0') + '-' + String(pickerDay).padStart(2,'0');
    var arr  = schedule[dt] || [];

    if (type === '') {
      delete schedule[dt];
      save(dt, '', '');
    } else {
      var idx = arr.indexOf(type);
      if (idx >= 0) {
        arr.splice(idx, 1);
        if (arr.length === 0) delete schedule[dt]; else schedule[dt] = arr;
        save(dt, type, 'remove');
      } else {
        arr.push(type);
        schedule[dt] = arr;
        save(dt, type, 'add');
      }
    }
    hidePicker();
    render();
  });

  // --- auto-refresh danych co 10 min (bez resetowania widoku) ---
  // Samokontrola przez WLASNY element, nie przez `getElementById('smc-grid')`: po ponownym
  // zaladowaniu karty nowa siatka ma ten sam id, wiec stary timer uznawal, ze zyje, i dalej
  // odpytywal `smieci_load` co 10 minut z martwego closure. Do tego rejestracja w sprzataniu
  // frameworka, ktore leci przed podmiana tresci karty.
  var smcGrid = document.getElementById('smc-grid');
  var smieciTimer = setInterval(function() {
    if (!smcGrid || !smcGrid.isConnected) { clearInterval(smieciTimer); return; }
    $.getJSON('api.php?action=smieci_load', function(data) {
      schedule = data;
      if (Date.now() - navigatedAt > 10 * 60 * 1000) {
        var n = new Date();
        curYear  = n.getFullYear();
        curMonth = n.getMonth() + 1;
      }
      render();
    });
  }, 10 * 60 * 1000);

  if (typeof cardOnCleanup === 'function') {
    cardOnCleanup(smcGrid, function() { clearInterval(smieciTimer); });
  }

  render();
})();
</script>
