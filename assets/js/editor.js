(function (wp, config) {
  if (!wp || !wp.data || !wp.components || !wp.editPost || !wp.plugins || !wp.element) {
    return;
  }
  if (window.__wpHeroColorEditorMounted) {
    return;
  }
  window.__wpHeroColorEditorMounted = true;

  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var useEffect = wp.element.useEffect;
  var useState = wp.element.useState;
  var useRef = wp.element.useRef;
  var useSelect = wp.data.useSelect;
  var registerPlugin = wp.plugins.registerPlugin;
  var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
  var Button = wp.components.Button;
  var SelectControl = wp.components.SelectControl;
  var Notice = wp.components.Notice;

  var MODES = [
    { label: "Main only", value: "solid" },
    { label: "Linear gradient", value: "linear" },
    { label: "Ambilight conic", value: "conic" }
  ];

  var DIRECTIONS = [
    { label: "Vertical", value: "vertical" },
    { label: "Horizontal", value: "horizontal" },
    { label: "Top-left to bottom-right", value: "diag_tl_br" },
    { label: "Top-right to bottom-left", value: "diag_tr_bl" }
  ];

  var pendingRequest = null;

  function parsePayloadFromMeta() {
    var meta = wp.data.select("core/editor").getEditedPostAttribute("meta") || {};
    var raw = meta._sr_hero_bg;
    if (!raw || typeof raw !== "string") {
      return null;
    }
    try {
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function setMetaPayload(payload) {
    var meta = wp.data.select("core/editor").getEditedPostAttribute("meta") || {};
    var nextMeta = Object.assign({}, meta, { _sr_hero_bg: JSON.stringify(payload) });
    wp.data.dispatch("core/editor").editPost({ meta: nextMeta });
  }

  function postCompute(opts) {
    if (!config || !config.restComputeUrl || !config.nonce) {
      return Promise.reject(new Error("Missing REST config"));
    }
    return window.fetch(config.restComputeUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": config.nonce
      },
      credentials: "same-origin",
      body: JSON.stringify(opts)
    }).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok) {
          var message = json && json.message ? json.message : "Compute failed";
          throw new Error(message);
        }
        return json;
      });
    });
  }

  function ColorSwatch(props) {
    return el("div", {
      style: {
        width: "20px",
        height: "20px",
        borderRadius: "3px",
        background: props.color,
        border: "1px solid #c3c4c7"
      },
      title: props.color
    });
  }

  function swatches(payload) {
    if (!payload || !Array.isArray(payload.edges)) {
      return null;
    }
    return el(
      "div",
      { style: { display: "grid", gridTemplateColumns: "repeat(8, 1fr)", gap: "6px", marginTop: "8px" } },
      payload.edges.map(function (color, idx) {
        return el(ColorSwatch, { key: "edge-" + idx, color: color });
      })
    );
  }

  function HeroColorPanel() {
    var postId = useSelect(function (select) {
      return select("core/editor").getCurrentPostId();
    }, []);
    var featuredMedia = useSelect(function (select) {
      return select("core/editor").getEditedPostAttribute("featured_media");
    }, []);
    var lastMediaDigestRef = useRef("");

    var initialPayload = parsePayloadFromMeta();
    var initialMode = (initialPayload && initialPayload.mode) || "solid";
    var initialDir = (initialPayload && initialPayload.linear_dir) || "vertical";
    var initialInline = (initialPayload && initialPayload.main) || "rgb(34,34,34)";

    var _useState = useState(initialPayload),
      payload = _useState[0],
      setPayload = _useState[1];
    var _useState2 = useState(initialMode),
      mode = _useState2[0],
      setMode = _useState2[1];
    var _useState3 = useState(initialDir),
      direction = _useState3[0],
      setDirection = _useState3[1];
    var _useState4 = useState(initialInline),
      previewBg = _useState4[0],
      setPreviewBg = _useState4[1];
    var _useState5 = useState(""),
      error = _useState5[0],
      setError = _useState5[1];
    var _useState6 = useState(false),
      busy = _useState6[0],
      setBusy = _useState6[1];

    function recompute(forceMode, forceDirection) {
      if (!postId || !featuredMedia) {
        return;
      }
      if (pendingRequest) {
        return;
      }
      pendingRequest = true;
      setBusy(true);
      setError("");
      postCompute({
        post_id: postId,
        attachment_id: featuredMedia,
        mode: forceMode || mode,
        linear_dir: forceDirection || direction
      })
        .then(function (res) {
          if (res && res.payload) {
            setPayload(res.payload);
            setMetaPayload(res.payload);
          }
          if (res && res.inline_style) {
            var match = res.inline_style.match(/--sr-hero-bg-image:([^;]+);/);
            if (match && match[1]) {
              setPreviewBg(match[1].trim());
            } else if (res.payload && res.payload.main) {
              setPreviewBg(res.payload.main);
            }
          }
        })
        .catch(function (err) {
          setError(err.message || "Unable to recompute.");
        })
        .finally(function () {
          pendingRequest = null;
          setBusy(false);
        });
    }

    useEffect(function () {
      if (!postId) {
        return;
      }
      if (!featuredMedia) {
        lastMediaDigestRef.current = "";
        return;
      }
      var digest = String(postId) + ":" + String(featuredMedia);
      if (digest === lastMediaDigestRef.current) {
        return;
      }
      lastMediaDigestRef.current = digest;
      recompute(mode, direction);
    }, [postId, featuredMedia]);

    return el(
      PluginDocumentSettingPanel,
      { name: "wp-hero-color-panel", title: "Hero Color", className: "wp-hero-color-panel" },
      error
        ? el(Notice, { status: "error", isDismissible: false }, error)
        : null,
      el(SelectControl, {
        label: "Mode",
        value: mode,
        options: MODES,
        onChange: function (nextMode) {
          setMode(nextMode);
          recompute(nextMode, direction);
        }
      }),
      mode === "linear"
        ? el(SelectControl, {
            label: "Linear direction",
            value: direction,
            options: DIRECTIONS,
            onChange: function (nextDirection) {
              setDirection(nextDirection);
              recompute(mode, nextDirection);
            }
          })
        : null,
      el(Button, { variant: "secondary", isBusy: busy, onClick: function () { recompute(mode, direction); } }, "Recompute"),
      el(
        "div",
        {
          style: {
            marginTop: "12px",
            width: "100%",
            aspectRatio: "16 / 10",
            border: "1px solid #dcdcde",
            borderRadius: "4px",
            background: previewBg
          }
        },
        null
      ),
      payload ? swatches(payload) : null,
      payload && payload.main
        ? el("p", { style: { marginTop: "8px", marginBottom: 0, fontFamily: "monospace", fontSize: "12px" } }, payload.main)
        : null
    );
  }

  if (typeof wp.plugins.unregisterPlugin === "function") {
    try {
      wp.plugins.unregisterPlugin("wp-hero-color-plugin");
    } catch (e) {}
  }

  registerPlugin("wp-hero-color-plugin", {
    render: function () {
      return el(Fragment, null, el(HeroColorPanel));
    }
  });
})(window.wp, window.wpHeroColorConfig);
