<script>
    $(document).ready(function () {

        // ── I1-I5 CPS fields contain angle brackets that get HTML-encoded
        // by the framework. Decode entities in these fields after form load.
        function decodeIFields() {
            var el = document.createElement('textarea');
            ['i1','i2','i3','i4','i5'].forEach(function (f) {
                var $input = $('[id="instance.' + f + '"]');
                var v = $input.val();
                if (v && (v.indexOf('&') !== -1)) {
                    // Decode repeatedly until stable (handles multi-level encoding)
                    var prev = v;
                    while (true) {
                        el.innerHTML = prev;
                        var decoded = el.value;
                        if (decoded === prev) break;
                        prev = decoded;
                    }
                    $input.val(prev);
                }
            });
        }

        // ── Load forms ────────────────────────────────────────────────
        mapDataToFormUI({'frm_general_settings': "/api/amneziawg/general/get"}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        mapDataToFormUI({'frm_instance_settings': "/api/amneziawg/instance/get"}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
            decodeIFields();
        });

        // ── Apply ─────────────────────────────────────────────────────
        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function () {
                _statusPaused = true;
                const dfObj = new $.Deferred();
                saveFormToEndpoint("/api/amneziawg/general/set", 'frm_general_settings', function () {
                    saveFormToEndpoint("/api/amneziawg/instance/set", 'frm_instance_settings', function () {
                        dfObj.resolve();
                    }, true, function () {
                        dfObj.resolve();
                    });
                }, true, function () {
                    dfObj.resolve();
                });
                return dfObj;
            },
            onAction: function (data, status) {
                if (data && data.status === 'disabled') {
                    _statusPaused = false;
                    $('#reconfigureAct_progress').addClass('hidden');
                    $('#reconfigureAct').prop('disabled', false);
                    BootstrapDialog.show({
                        type:    BootstrapDialog.TYPE_INFO,
                        title:   "{{ lang._('AmneziaWG') }}",
                        message: "{{ lang._('AmneziaWG is disabled. Enable it on the General tab and apply again.') }}",
                        buttons: [{ label: "{{ lang._('Close') }}", action: function (d) { d.close(); } }]
                    });
                    return;
                }
                if (data && data.result === 'ok') {
                    $('#reconfigureAct_progress').addClass('hidden');
                    $('#reconfigureAct').prop('disabled', false);
                    setTimeout(function () { _statusPaused = false; updateStatus(); }, 2000);
                } else {
                    _statusPaused = false;
                    BootstrapDialog.show({
                        type:    BootstrapDialog.TYPE_DANGER,
                        title:   "{{ lang._('Error') }}",
                        message: "{{ lang._('Error reconfiguring AmneziaWG service.') }}" +
                                 (data && data.output ? '<br><code>' + data.output + '</code>' : ''),
                        buttons: [{ label: "{{ lang._('Close') }}", action: function (d) { d.close(); } }]
                    });
                }
            }
        });

        // ── Status badge ──────────────────────────────────────────────
        var _statusTimer = null;
        var _statusPaused = false;

        function updateStatus() {
            if (_statusPaused) return;
            ajaxGet("/api/amneziawg/service/tunnel_status", {}, function (data) {
                var running = (data.status === 'ok' && data.tunnels && data.tunnels.length > 0);
                $('#badge_awg')
                    .removeClass('label-success label-danger label-default')
                    .addClass(running ? 'label-success' : 'label-danger')
                    .text('awg: ' + (running ? 'running' : 'stopped'));

                if (!_statusPaused) {
                    $('#btnStart').prop('disabled', running);
                    $('#btnStop').prop('disabled', !running);
                }

                // Extract public key from tunnel details
                if (running) {
                    var details = data.tunnels[0].details || '';
                    var match = details.match(/public key:\s*(\S+)/i);
                    if (match) {
                        $('#awg-pubkey-display').text(match[1]).closest('.awg-pubkey-row').show();
                    }
                }
            });
        }
        updateStatus();
        _statusTimer = setInterval(updateStatus, 10000);

        // ── Start / Stop / Restart ────────────────────────────────────
        function serviceAction(action, confirmMsg) {
            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }
            // Pause status polling to avoid configd contention
            _statusPaused = true;

            var $btns = $('#btnStart, #btnStop, #btnRestart').prop('disabled', true);
            var $btn = $('#btn' + action.charAt(0).toUpperCase() + action.slice(1));
            var origHtml = $btn.html();
            $btn.html('<i class="fa fa-spinner fa-spin"></i>');

            $.ajax({
                url:      '/api/amneziawg/service/' + action,
                type:     'POST',
                dataType: 'json',
                timeout:  65000,
                success: function (data) {
                    $btn.html(origHtml);
                    if (data.result !== 'ok') {
                        alert('{{ lang._("Action failed:") }} ' + (data.message || 'unknown error'));
                    }
                    setTimeout(function () {
                        _statusPaused = false;
                        updateStatus();
                        $btns.prop('disabled', false);
                    }, 2000);
                },
                error: function (xhr) {
                    $btn.html(origHtml);
                    $btns.prop('disabled', false);
                    _statusPaused = false;
                    if (xhr.statusText === 'timeout') {
                        alert('{{ lang._("Request timed out. Check tunnel status manually.") }}');
                    } else {
                        alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                    }
                }
            });
        }

        $('#btnStart').click(function () {
            serviceAction('start', null);
        });

        $('#btnStop').click(function () {
            serviceAction('stop', '{{ lang._("Stop AmneziaWG? Active tunnel will be terminated.") }}');
        });

        $('#btnRestart').click(function () {
            serviceAction('restart', null);
        });

        // ── Keypair generation ────────────────────────────────────────
        $("#keygen").click(function () {
            ajaxGet("/api/amneziawg/instance/gen_key_pair", {}, function (data) {
                if (data.status && data.status === 'ok') {
                    $('[id="instance.private_key"]').val(data.private_key);
                    if (data.public_key) {
                        $('#awg-pubkey-display').text(data.public_key).closest('.awg-pubkey-row').show();
                    }
                }
            });
        });

        // ── Import .conf parser ───────────────────────────────────────
        $("#importParseBtn").click(function () {
            ajaxCall("/api/amneziawg/import/parse", {config: $("#importConfigText").val()}, function (data) {
                if (data.status === 'ok') {
                    var fields = ['private_key','address','dns','mtu',
                                  'jc','jmin','jmax','s1','s2','h1','h2','h3','h4',
                                  'i1','i2','i3','i4','i5',
                                  'peer_public_key','peer_preshared_key','peer_endpoint',
                                  'peer_allowed_ips','peer_persistent_keepalive'];
                    fields.forEach(function (f) {
                        if (data[f] !== undefined && data[f] !== '') {
                            $('[id="instance.' + f + '"]').val(data[f]);
                        }
                    });
                    decodeIFields();
                    $("#importModal").modal('hide');
                    $('a[href="#instance"]').tab('show');
                } else {
                    alert(data.message || "{{ lang._('Parse error') }}");
                }
            });
        });

        // ── Diagnostics tab ──────────────────────────────────────────
        function loadDiagnostics() {
            $('#diagLoading').show();
            $('#diagError').hide();
            ajaxGet('/api/amneziawg/service/diagnostics', {}, function (data) {
                $('#diagLoading').hide();
                if (data.error) {
                    $('#diagError').text(data.error).show();
                    return;
                }
                var running = (data.status === 'running');
                $('#diag_status').html(running
                    ? '<span class="label label-success">running</span>'
                    : '<span class="label label-danger">' + (data.status || 'unknown') + '</span>');
                $('#diag_interface').text(data.interface || '-');
                $('#diag_ip').text(data.ip || '-');
                $('#diag_mtu').text(data.mtu || '-');
                $('#diag_pubkey').text(data.public_key || '-');
                $('#diag_listen_port').text(data.listen_port || '-');
                $('#diag_peer_pubkey').text(data.peer_public_key || '-');
                $('#diag_peer_endpoint').text(data.peer_endpoint || '-');
                $('#diag_peer_allowed_ips').text(data.peer_allowed_ips || '-');
                $('#diag_handshake').text(data.latest_handshake || 'never');
                $('#diag_transfer_rx').text(data.transfer_rx || '0 B');
                $('#diag_transfer_tx').text(data.transfer_tx || '0 B');
                $('#diag_netstat_rx').text((data.bytes_in_hr || '0 B') + ' (' + (data.packets_in || 0) + ' pkts)');
                $('#diag_netstat_tx').text((data.bytes_out_hr || '0 B') + ' (' + (data.packets_out || 0) + ' pkts)');
                $('#diag_uptime').text(data.uptime || '-');
            });
        }

        var _diagAutoRefresh = null;
        $('a[href="#diagnostics"]').on('shown.bs.tab', function () {
            loadDiagnostics();
            if (!_diagAutoRefresh) {
                _diagAutoRefresh = setInterval(function () {
                    if ($('#diagnostics').hasClass('active')) {
                        loadDiagnostics();
                    }
                }, 30000);
            }
        });

        $('#btnDiagRefresh').click(function () {
            loadDiagnostics();
        });

        // ── Test connection ─────────────────────────────────────────
        $('#btnTestConnect').click(function () {
            var $btn = $(this);
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> {{ lang._("Testing...") }}');
            $('#testResult').hide();
            $.ajax({
                url: '/api/amneziawg/service/testconnect',
                type: 'POST',
                dataType: 'json',
                timeout: 20000,
                success: function (data) {
                    $btn.prop('disabled', false).html('<i class="fa fa-bolt"></i> {{ lang._("Test Connection") }}');
                    var ok = (data.status === 'ok');
                    $('#testResult')
                        .removeClass('alert-success alert-danger')
                        .addClass(ok ? 'alert-success' : 'alert-danger')
                        .html('<strong>' + (ok ? '{{ lang._("Success") }}' : '{{ lang._("Failed") }}') + ':</strong> ' + (data.message || ''))
                        .show();
                },
                error: function () {
                    $btn.prop('disabled', false).html('<i class="fa fa-bolt"></i> {{ lang._("Test Connection") }}');
                    $('#testResult').removeClass('alert-success').addClass('alert-danger')
                        .html('<strong>{{ lang._("Error") }}:</strong> {{ lang._("Request failed") }}').show();
                }
            });
        });

        // ── Log tab ─────────────────────────────────────────────────
        function loadLog() {
            $('#btnLogRefresh').prop('disabled', true);
            $('#logContent').text('{{ lang._("Loading...") }}');
            $.post('/api/amneziawg/service/log', null, function (data) {
                $('#logContent').text(data.log || '{{ lang._("Log is empty") }}');
                $('#btnLogRefresh').prop('disabled', false);
            }, 'json').fail(function () {
                $('#logContent').text('{{ lang._("Failed to load log") }}');
                $('#btnLogRefresh').prop('disabled', false);
            });
        }

        $('a[href="#logs"]').on('shown.bs.tab', function () {
            loadLog();
        });

        $('#btnLogRefresh').click(function () {
            loadLog();
        });

        // ── Copy Debug Info ─────────────────────────────────────────
        $('#btnCopyDebug').click(function () {
            var diagDone = $.Deferred(), logDone = $.Deferred();
            var diagData = {}, logText = '';

            ajaxGet('/api/amneziawg/service/diagnostics', {}, function (data) {
                diagData = data;
                diagDone.resolve();
            });
            $.post('/api/amneziawg/service/log', null, function (data) {
                logText = (data && data.log) || '';
                logDone.resolve();
            }, 'json').fail(function () { logDone.resolve(); });

            $.when(diagDone, logDone).done(function () {
                var info = '=== os-amneziawg Debug Info ===\n'
                    + 'Date: ' + new Date().toISOString() + '\n\n'
                    + '=== Diagnostics ===\n'
                    + JSON.stringify(diagData, null, 2) + '\n\n'
                    + '=== Log (last 150 lines) ===\n'
                    + logText + '\n';
                $('#debugInfoContent').val(info);
                $('#debugInfoModal').modal('show');
            });
        });

        // ── Validate config ─────────────────────────────────────────
        $('#btnValidate').click(function () {
            var $btn = $(this);
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
            $.ajax({
                url: '/api/amneziawg/service/validate',
                type: 'POST',
                dataType: 'json',
                timeout: 15000,
                success: function (data) {
                    $btn.prop('disabled', false).html('<i class="fa fa-check-circle"></i> {{ lang._("Validate Config") }}');
                    var ok = (data.result === 'ok');
                    BootstrapDialog.show({
                        type: ok ? BootstrapDialog.TYPE_SUCCESS : BootstrapDialog.TYPE_DANGER,
                        title: '{{ lang._("Config Validation") }}',
                        message: ok ? '{{ lang._("Configuration is valid.") }}' : ('{{ lang._("Validation failed:") }} ' + (data.message || '')),
                        buttons: [{ label: '{{ lang._("Close") }}', action: function (d) { d.close(); } }]
                    });
                },
                error: function () {
                    $btn.prop('disabled', false).html('<i class="fa fa-check-circle"></i> {{ lang._("Validate Config") }}');
                }
            });
        });

        // ── Tab hash ──────────────────────────────────────────────────
        if (window.location.hash !== "") {
            $('a[href="' + window.location.hash + '"]').click();
        }
        $('.nav-tabs a').on('shown.bs.tab', function (e) {
            history.pushState(null, null, e.target.hash);
        });
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#instance">{{ lang._('Instance') }}</a></li>
    <li><a data-toggle="tab" href="#general">{{ lang._('General') }}</a></li>
    <li><a data-toggle="tab" href="#diagnostics">{{ lang._('Diagnostics') }}</a></li>
    <li><a data-toggle="tab" href="#logs">{{ lang._('Log') }}</a></li>
</ul>

<div class="tab-content content-box">

    <div id="instance" class="tab-pane fade in active">
        {# Status badge + service control buttons #}
        <div style="padding: 10px 15px 6px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px;">
            <span id="badge_awg" class="label label-default">awg: ...</span>

            <span style="margin-left: 4px; border-left: 1px solid #ddd; padding-left: 8px; display: inline-flex; gap: 4px;">
                <button id="btnStart" class="btn btn-xs btn-success" title="{{ lang._('Start AmneziaWG tunnel') }}">
                    <i class="fa fa-play"></i> {{ lang._('Start') }}
                </button>
                <button id="btnStop" class="btn btn-xs btn-danger" title="{{ lang._('Stop AmneziaWG tunnel') }}">
                    <i class="fa fa-stop"></i> {{ lang._('Stop') }}
                </button>
                <button id="btnRestart" class="btn btn-xs btn-warning" title="{{ lang._('Restart without saving config') }}">
                    <i class="fa fa-refresh"></i> {{ lang._('Restart') }}
                </button>
            </span>
        </div>

        <div style="padding: 0 15px 8px; display: flex; gap: 6px;">
            <button class="btn btn-sm btn-default" data-toggle="modal" data-target="#importModal">
                <i class="fa fa-upload"></i> {{ lang._('Import .conf') }}
            </button>
            <button id="keygen" type="button" class="btn btn-sm btn-default" title="{{ lang._('Generate new keypair') }}">
                <i class="fa fa-gear"></i> {{ lang._('Generate Keypair') }}
            </button>
        </div>

        <!-- Public key display row, hidden until key is available -->
        <div class="awg-pubkey-row" style="display:none; padding: 6px 15px 2px;">
            <div class="form-group">
                <label class="col-md-3 control-label">{{ lang._('Public Key') }}</label>
                <div class="col-md-9">
                    <p class="form-control-static">
                        <code id="awg-pubkey-display" style="word-break:break-all;"></code>
                        <button type="button" class="btn btn-xs btn-default" style="margin-left:8px;"
                            onclick="navigator.clipboard && navigator.clipboard.writeText($('#awg-pubkey-display').text())"
                            title="{{ lang._('Copy to clipboard') }}">
                            <i class="fa fa-clipboard"></i>
                        </button>
                    </p>
                    <span class="help-block">{{ lang._('Derived from your private key. Share this with the server administrator.') }}</span>
                </div>
            </div>
        </div>
        {{ partial("layout_partials/base_form", ['fields': instanceForm, 'id': 'frm_instance_settings']) }}
    </div>

    <div id="general" class="tab-pane fade in">
        {{ partial("layout_partials/base_form", ['fields': generalForm, 'id': 'frm_general_settings']) }}
    </div>

    <div id="diagnostics" class="tab-pane fade in">
        <div style="padding: 15px;">
            <div style="margin-bottom: 10px; display: flex; gap: 6px; align-items: center;">
                <button id="btnDiagRefresh" class="btn btn-sm btn-default">
                    <i class="fa fa-refresh"></i> {{ lang._('Refresh') }}
                </button>
                <button id="btnTestConnect" class="btn btn-sm btn-primary">
                    <i class="fa fa-bolt"></i> {{ lang._('Test Connection') }}
                </button>
                <button id="btnValidate" class="btn btn-sm btn-default">
                    <i class="fa fa-check-circle"></i> {{ lang._('Validate Config') }}
                </button>
                <button id="btnCopyDebug" class="btn btn-sm btn-default">
                    <i class="fa fa-clipboard"></i> {{ lang._('Copy Debug Info') }}
                </button>
            </div>

            <div id="testResult" class="alert" style="display: none;"></div>
            <div id="diagError" class="alert alert-danger" style="display: none;"></div>
            <div id="diagLoading" style="display: none;">
                <i class="fa fa-spinner fa-spin"></i> {{ lang._('Loading...') }}
            </div>

            <table class="table table-striped table-condensed">
                <thead>
                    <tr><th colspan="2">{{ lang._('Interface') }}</th></tr>
                </thead>
                <tbody>
                    <tr><td style="width:200px;">{{ lang._('Status') }}</td><td id="diag_status">-</td></tr>
                    <tr><td>{{ lang._('Interface') }}</td><td id="diag_interface">-</td></tr>
                    <tr><td>{{ lang._('IP Address') }}</td><td id="diag_ip">-</td></tr>
                    <tr><td>{{ lang._('MTU') }}</td><td id="diag_mtu">-</td></tr>
                    <tr><td>{{ lang._('Public Key') }}</td><td id="diag_pubkey" style="word-break:break-all;">-</td></tr>
                    <tr><td>{{ lang._('Listen Port') }}</td><td id="diag_listen_port">-</td></tr>
                    <tr><td>{{ lang._('Uptime') }}</td><td id="diag_uptime">-</td></tr>
                </tbody>
                <thead>
                    <tr><th colspan="2">{{ lang._('Peer') }}</th></tr>
                </thead>
                <tbody>
                    <tr><td>{{ lang._('Public Key') }}</td><td id="diag_peer_pubkey" style="word-break:break-all;">-</td></tr>
                    <tr><td>{{ lang._('Endpoint') }}</td><td id="diag_peer_endpoint">-</td></tr>
                    <tr><td>{{ lang._('Allowed IPs') }}</td><td id="diag_peer_allowed_ips">-</td></tr>
                    <tr><td>{{ lang._('Latest Handshake') }}</td><td id="diag_handshake">-</td></tr>
                    <tr><td>{{ lang._('Persistent Keepalive') }}</td><td>-</td></tr>
                </tbody>
                <thead>
                    <tr><th colspan="2">{{ lang._('Traffic') }}</th></tr>
                </thead>
                <tbody>
                    <tr><td>{{ lang._('Transfer RX (awg)') }}</td><td id="diag_transfer_rx">-</td></tr>
                    <tr><td>{{ lang._('Transfer TX (awg)') }}</td><td id="diag_transfer_tx">-</td></tr>
                    <tr><td>{{ lang._('Netstat RX') }}</td><td id="diag_netstat_rx">-</td></tr>
                    <tr><td>{{ lang._('Netstat TX') }}</td><td id="diag_netstat_tx">-</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="logs" class="tab-pane fade in">
        <div style="padding: 15px;">
            <div style="margin-bottom: 10px;">
                <button id="btnLogRefresh" class="btn btn-sm btn-default">
                    <i class="fa fa-refresh"></i> {{ lang._('Refresh') }}
                </button>
            </div>
            <pre id="logContent" style="max-height: 500px; overflow-y: auto; font-size: 12px; background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 4px;">{{ lang._('Switch to this tab to load log...') }}</pre>
        </div>
    </div>

</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/amneziawg/service/reconfigure'}) }}

<!-- Debug Info Modal -->
<div class="modal fade" id="debugInfoModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">
                    <i class="fa fa-clipboard"></i> {{ lang._('Debug Info') }}
                </h4>
            </div>
            <div class="modal-body">
                <p class="text-muted">{{ lang._('Copy this information and share it when reporting issues.') }}</p>
                <textarea id="debugInfoContent" class="form-control" rows="20"
                    style="font-family: monospace; font-size: 11px;" readonly></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary"
                    onclick="$('#debugInfoContent').select(); document.execCommand('copy');">
                    <i class="fa fa-clipboard"></i> {{ lang._('Copy to Clipboard') }}
                </button>
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Close') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">
                    <i class="fa fa-upload"></i> {{ lang._('Import AmneziaWG Configuration') }}
                </h4>
            </div>
            <div class="modal-body">
                <p class="text-muted">
                    {{ lang._('Paste the contents of your AmneziaWG client .conf file. All fields will be filled automatically.') }}
                </p>
                <textarea id="importConfigText" class="form-control" rows="18"
                    style="font-family: monospace; font-size: 12px;"
                    placeholder="[Interface]&#10;PrivateKey = ...&#10;Address = 10.8.1.2/24&#10;Jc = 4&#10;...&#10;&#10;[Peer]&#10;PublicKey = ...&#10;Endpoint = 1.2.3.4:51820&#10;AllowedIPs = 0.0.0.0/0"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="importParseBtn">
                    <i class="fa fa-magic"></i> {{ lang._('Parse & Fill') }}
                </button>
            </div>
        </div>
    </div>
</div>
