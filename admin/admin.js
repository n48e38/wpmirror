(function($){
  function pct(current, total){
    if(!total || total <= 0) return 0;
    return Math.max(0, Math.min(100, Math.round((current/total)*100)));
  }

  function renderStatus(data){
    var p = data.progress || {current:0,total:0};
    var percent = pct(p.current, p.total);

    $('#wp-mirror-progress-bar').css('width', percent + '%');

    var statusLine = (data.status || 'idle') + (data.stage ? (' • ' + data.stage) : '');
    var progressLine = (p.total ? (p.current + '/' + p.total + ' (' + percent + '%)') : '');
    var msg = data.message || '';
    var extra = '';

    if(data.status === 'paused' && data.deploy){
      var until = data.deploy.pause_until ? new Date(data.deploy.pause_until * 1000).toISOString().replace('T',' ').replace('Z',' UTC') : '';
      extra = ' • Paused: ' + (data.deploy.pause_reason || 'rate limit') + (until ? (' • Resume after ' + until) : '');
    }

    $('#wp-mirror-progress-text').text(statusLine + (progressLine ? (' • ' + progressLine) : '') + (msg ? (' • ' + msg) : '') + extra);

    var log = (data.log || []).slice(-200);
    var html = log.map(function(l){ return $('<div/>').text(l).html(); }).join('<br/>');
    $('#wp-mirror-log').html(html || '<span class="wp-mirror-muted">No logs yet.</span>');

    var errors = (data.errors || []);
    if(errors.length){
      $('#wp-mirror-errors').html('<strong>Errors:</strong><br/>' + errors.map(function(e){ return $('<div/>').text(e).html(); }).join('<br/>'));
    } else {
      $('#wp-mirror-errors').empty();
    }
  }

  function poll(){
    $.post(WPMirror.ajaxUrl, {action:'wp_mirror_status', nonce: WPMirror.nonce})
      .done(function(resp){
        if(resp && resp.success){
          renderStatus(resp.data);
        }
      })
      .always(function(){
        setTimeout(poll, 3000);
      });
  }

  $(function(){
    if(typeof WPMirror === 'undefined') return;
    poll();

    $('#wp-mirror-cancel-deploy').on('click', function(e){
      e.preventDefault();
      $.post(WPMirror.ajaxUrl, {action:'wp_mirror_cancel_deploy', nonce: WPMirror.nonce});
    });

    $('#wp-mirror-retry-failed').on('click', function(e){
      e.preventDefault();
      $.post(WPMirror.ajaxUrl, {action:'wp_mirror_retry_failed', nonce: WPMirror.nonce});
    });

    $('#wp-mirror-test-github').on('click', function(e){
      e.preventDefault();
      $('#wp-mirror-test-result').text('Testing...');
      $.post(WPMirror.ajaxUrl, {action:'wp_mirror_test_github', nonce: WPMirror.nonce})
        .done(function(resp){
          if(resp && resp.success){
            var login = resp.data && resp.data.login ? (' (' + resp.data.login + ')') : '';
            $('#wp-mirror-test-result').text(resp.data.message + login);
          } else {
            var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Test failed';
            $('#wp-mirror-test-result').text(msg);
          }
        })
        .fail(function(){
          $('#wp-mirror-test-result').text('Test failed');
        });
    });
  });
})(jQuery);
