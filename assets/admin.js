(function($){
  function openDialog($el, title){
    $el.dialog({
      modal: true,
      width: 560,
      title: title,
      closeText: '×'
    });
  }

  $(document).on('click', '.srp-open-user-modal', function(){
    var $btn = $(this);
    var name = String($btn.attr('data-name') || '');

    if (!name) {
      var $tr = $btn.closest('tr');
      var n = ($tr.find('td').eq(0).text() || '').trim();
      var e = ($tr.find('td').eq(1).text() || '').trim();
      name = n;
      if (e) name = (name ? (name + ' — ' + e) : e);
    }

    $('#srp_user_id').val($btn.attr('data-user-id') || '');
    $('#srp_user_name').val(name);
    
    // New Fields Population
    $('#srp_user_company').val(String($btn.attr('data-company') || ''));
    $('#srp_user_address').val(String($btn.attr('data-address') || ''));
    $('#srp_user_phone').val(String($btn.attr('data-phone') || ''));
    $('#srp_user_vat').val(String($btn.attr('data-vat') || ''));
    $('#srp_user_country').val(String($btn.attr('data-country') || ''));
    
    $('#srp_user_type').val(String($btn.attr('data-type-id') || 0));
    $('#srp_user_status').val(String($btn.attr('data-status') || 'pending'));

    openDialog($('#srp-user-modal'), (window.SRP_Admin && SRP_Admin.i18n ? SRP_Admin.i18n.manageUser : 'Manage User'));
  });

  $(document).on('click', '.srp-open-type-modal', function(){
    var d = $(this).data();
    $('#srp_type_id').val(d.id || '');
    $('#srp_type_name').val(d.name || '');
    $('#srp_type_status').val(d.status || 'active');
    openDialog($('#srp-type-modal'), 'Edit Customer Type');
  });

  function setupProductAutocomplete($input, $hidden){
    if (!$input.length || !$hidden.length) return;
    $input.autocomplete({
      minLength: 2,
      delay: 200,
      source: function(request, response){
        $.getJSON(SRP_Admin.ajaxUrl, {
          action: 'srp_search_products',
          nonce: SRP_Admin.nonce,
          term: request.term
        }).done(function(data){
          response(data || []);
        }).fail(function(){
          response([]);
        });
      },
      select: function(event, ui){
        $hidden.val(ui.item.id || 0);
        $input.val(ui.item.label || ui.item.value || '');
        $hidden.trigger('change');
        return false;
      },
      change: function(){
        if (!$input.val().trim()) {
          $hidden.val(0).trigger('change');
        }
      }
    });
  }

  function setupUserAutocomplete($input, $hidden){
    if (!$input.length || !$hidden.length) return;
    $input.autocomplete({
      minLength: 2,
      delay: 200,
      source: function(request, response){
        $.getJSON(SRP_Admin.ajaxUrl, {
          action: 'srp_search_users',
          nonce: SRP_Admin.nonce,
          term: request.term
        }).done(function(data){
          var out = [];
          (data || []).forEach(function(x){
            out.push({ id: x.id, label: x.text, value: x.text });
          });
          response(out);
        }).fail(function(){
          response([]);
        });
      },
      select: function(event, ui){
        $hidden.val(ui.item.id || 0);
        $input.val(ui.item.label || ui.item.value || '');
        $hidden.trigger('change');
        return false;
      },
      change: function(){
        if (!$input.val().trim()) {
          $hidden.val(0).trigger('change');
        }
      }
    });
  }

  function syncObjectId(scope, prefix){
    var objectId = 0;
    if (scope === 'category') objectId = parseInt($(prefix + '_category_id').val() || 0, 10);
    if (scope === 'brand') objectId = parseInt($(prefix + '_brand_id').val() || 0, 10);
    if (scope === 'product' || scope === 'user') {
      objectId = parseInt($(prefix + '_product_id').val() || 0, 10);
    }
    $(prefix + '_object_id').val(objectId || 0);
  }

  function toggleRuleForm(scope, isEdit){
    if (!isEdit){
      $('.srp-owner-type').toggle(scope !== 'user');
      $('.srp-owner-user').toggle(scope === 'user');
      $('#srp_rule_type_id').prop('required', scope !== 'user');
    } else {
      $('.srp-edit-owner-type').toggle(scope !== 'user');
      $('.srp-edit-owner-user').toggle(scope === 'user');
    }

    var catCls = isEdit ? '.srp-edit-target-category' : '.srp-target-category';
    var brandCls = isEdit ? '.srp-edit-target-brand' : '.srp-target-brand';
    var prodCls = isEdit ? '.srp-edit-target-product' : '.srp-target-product';
    $(catCls).toggle(scope === 'category');
    $(brandCls).toggle(scope === 'brand');
    $(prodCls).toggle(scope === 'product' || scope === 'user');
  }

  $(function(){
    setupProductAutocomplete($('#srp_rule_product_search'), $('#srp_rule_product_id'));
    setupUserAutocomplete($('#srp_rule_user_search'), $('#srp_rule_user_id'));

    toggleRuleForm($('#srp_rule_scope').val(), false);
    syncObjectId($('#srp_rule_scope').val(), '#srp_rule');

    $('#srp_rule_scope').on('change', function(){
      toggleRuleForm($(this).val(), false);
      syncObjectId($(this).val(), '#srp_rule');
    });

    $('#srp_rule_category_id, #srp_rule_brand_id, #srp_rule_product_id').on('change', function(){
      syncObjectId($('#srp_rule_scope').val(), '#srp_rule');
    });

    // --- Drag and Drop Logic Start ---
    
    // Helper to fix table row width while dragging
    var fixHelper = function(e, ui) {
        ui.children().each(function() {
            $(this).width($(this).width());
        });
        return ui;
    };

    // Sortable for Customer Types
    if ($('.srp-sortable-types').length && typeof $.fn.sortable !== 'undefined') {
        $('.srp-sortable-types').sortable({
            helper: fixHelper,
            cursor: 'move',
            opacity: 0.8,
            items: '> tr', 
            handle: 'td:first-child', 
            zIndex: 9999, 
            update: function(event, ui) {
                var order = [];
                $('.srp-sortable-types tr').each(function() {
                    order.push($(this).data('id'));
                });
                
                $.post(SRP_Admin.ajaxUrl, {
                    action: 'srp_update_type_order',
                    nonce: SRP_Admin.nonce,
                    order: order
                });
            }
        });
    }

    // Sortable for Pricing Rules
    if ($('.srp-sortable-rules').length && typeof $.fn.sortable !== 'undefined') {
        $('.srp-sortable-rules').sortable({
            helper: fixHelper,
            cursor: 'move',
            opacity: 0.8,
            items: '> tr',
            handle: 'td:first-child',
            zIndex: 9999,
            update: function(event, ui) {
                var order = [];
                $('.srp-sortable-rules tr').each(function() {
                    order.push($(this).data('id'));
                });
                
                $.post(SRP_Admin.ajaxUrl, {
                    action: 'srp_update_rule_order',
                    nonce: SRP_Admin.nonce,
                    order: order
                });
            }
        });
    }
    // --- Drag and Drop Logic End ---
  });

  $(document).on('click', '.srp-open-rule-modal', function(){
    var d = $(this).data();

    $('#srp_rule_id').val(d.id);
    $('#srp_rule_scope_edit').val(d.scope);
    $('#srp_rule_type_edit').val(d.ruleType);
    $('#srp_rule_value_edit').val(d.value);

    $('#srp_rule_type_id_edit').val('');
    $('#srp_rule_user_id_edit').val(0);
    $('#srp_rule_user_search_edit').val('');
    $('#srp_rule_category_id_edit').val('');
    $('#srp_rule_brand_id_edit').val('');
    $('#srp_rule_product_id_edit').val(0);
    $('#srp_rule_product_search_edit').val('');

    setupProductAutocomplete($('#srp_rule_product_search_edit'), $('#srp_rule_product_id_edit'));
    setupUserAutocomplete($('#srp_rule_user_search_edit'), $('#srp_rule_user_id_edit'));

    toggleRuleForm(d.scope, true);

    var objectId = parseInt(d.objectId || 0, 10);
    $('#srp_rule_object_id_edit').val(objectId || 0);

    if (d.scope === 'category') {
      $('#srp_rule_category_id_edit').val(String(objectId));
    } else if (d.scope === 'brand') {
      $('#srp_rule_brand_id_edit').val(String(objectId));
    } else if (d.scope === 'product') {
      $('#srp_rule_product_id_edit').val(objectId || 0);
      $('#srp_rule_product_search_edit').val(d.productName || ('Product #' + objectId));
    } else if (d.scope === 'user') {
      var userId = parseInt(d.typeId || 0, 10);
      $('#srp_rule_user_id_edit').val(userId || 0);
      $('#srp_rule_user_search_edit').val(d.userName || ('User #' + userId));
      $('#srp_rule_product_id_edit').val(objectId || 0);
      $('#srp_rule_product_search_edit').val(d.productName || ('Product #' + objectId));
    }

    if (d.scope !== 'user') {
      $('#srp_rule_type_id_edit').val(String(d.typeId || ''));
    }

    openDialog($('#srp-rule-modal'), (window.SRP_Admin && SRP_Admin.i18n ? SRP_Admin.i18n.editRule : 'Edit Rule'));
  });

  $(document).on('change', '#srp_rule_scope_edit', function(){
    var scope = $(this).val();
    toggleRuleForm(scope, true);
  });
  $(document).on('change', '#srp_rule_category_id_edit', function(){
    $('#srp_rule_object_id_edit').val(parseInt($(this).val()||0,10));
  });
  $(document).on('change', '#srp_rule_brand_id_edit', function(){
    $('#srp_rule_object_id_edit').val(parseInt($(this).val()||0,10));
  });
  $(document).on('change', '#srp_rule_product_id_edit', function(){
    $('#srp_rule_object_id_edit').val(parseInt($(this).val()||0,10));
  });

})(jQuery);