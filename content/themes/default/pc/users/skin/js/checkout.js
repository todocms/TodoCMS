/*!
 * TodoCMS Checkout Page Script
 * Basic UI interactions to avoid console errors and enable totals recalculation.
 */
(function() {
  'use strict';

  function qs(sel) { return document.querySelector(sel); }
  function qsa(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

  function showToast(msg, icon) {
    if (window.layer) {
      layer.msg(msg, icon !== undefined ? { icon: icon } : undefined);
    } else if (typeof TodoUser !== 'undefined' && TodoUser.showMessage) {
      TodoUser.showMessage(msg, icon === 2 || icon === 'error' ? 'error' : 'info');
    } else {
      alert(msg);
    }
  }

  // 统一站点URL，避免子目录/域名差异及多语言路径干扰
  var rawSiteUrl = ((window.TODO && window.TODO.SITE_URL) ||
    (document.querySelector('meta[name="site-url"]') ? document.querySelector('meta[name="site-url"]').getAttribute('content') : '') ||
    window.location.origin).replace(/\/+$/, '');
  var SITE_URL = rawSiteUrl;
  try {
    var parsedSite = new URL(rawSiteUrl, window.location.origin);
    var basePath = parsedSite.pathname || '/';
    var langMatch = basePath.match(/^(.*)\/(?:[a-z]{2}(?:-[A-Z]{2})?)\/?$/);
    if (langMatch) { basePath = langMatch[1] || '/'; }
    SITE_URL = (parsedSite.origin + basePath).replace(/\/+$/, '');
  } catch(e) {}
  // 防重复提交标记（模块作用域）
  var submitting = false;

  function parseAmount(txt) {
    if (!txt) return 0;
    var n = String(txt).replace(/[^\d.]/g, '');
    var f = parseFloat(n);
    return isNaN(f) ? 0 : f;
  }

  function checkMixedProducts() {
    var items = qsa('.order-item');
    if (items.length <= 1) return false;
    var hasPhysical = false;
    var hasVirtual = false;
    items.forEach(function(item) {
      var pt = item.getAttribute('data-product-type') || 'physical';
      if (pt === 'virtual') {
        hasVirtual = true;
      } else {
        hasPhysical = true;
      }
    });
    return (hasPhysical && hasVirtual);
  }
  window.checkMixedProducts = checkMixedProducts;

  function isVirtualCheckout() {
    var form = qs('#checkout-form');
    if (form && form.getAttribute('data-is-virtual') === '1') return true;
    
    var items = qsa('.order-item');
    if (items.length > 0) {
      var allVirtual = true;
      items.forEach(function(item) {
        var pt = item.getAttribute('data-product-type');
        if (pt !== 'virtual') {
          allVirtual = false;
        }
      });
      if (allVirtual) return true;
    }
    return false;
  }
  window.isVirtualCheckout = isVirtualCheckout;

  function updateVirtualState(isVirtual) {
    var addrSection = qs('#address-section');
    var shipSection = qs('#shipping-section');
    var guestSec = qs('#guest-info-section');
    
    if (isVirtual) {
      if (addrSection) {
        addrSection.style.setProperty('display', 'none', 'important');
        var parentSec = addrSection.closest('.checkout-section');
        if (parentSec) {
          if (!guestSec || window.getComputedStyle(guestSec).display === 'none') {
            parentSec.style.setProperty('display', 'none', 'important');
          }
        }
      }
      if (shipSection) {
        shipSection.style.setProperty('display', 'none', 'important');
      }
      
      // 移除收货地址输入框 required 属性
      var addressInputs = ['input-name', 'input-phone', 'input-province', 'input-city', 'input-district', 'input-address', 'input-intl-country', 'input-intl-state', 'input-intl-city', 'input-intl-address', 'input-intl-zipcode'];
      addressInputs.forEach(function(id) {
        var el = qs('#' + id);
        if (el) el.removeAttribute('required');
      });
      
      var form = qs('#checkout-form');
      if (form) form.setAttribute('data-is-virtual', '1');
    } else {
      if (addrSection) {
        addrSection.style.display = '';
        var parentSec = addrSection.closest('.checkout-section');
        if (parentSec) parentSec.style.display = '';
      }
      if (shipSection) {
        shipSection.style.display = '';
      }
      var form = qs('#checkout-form');
      if (form) form.setAttribute('data-is-virtual', '0');
    }
    updateTotals();
  }
  window.updateVirtualState = updateVirtualState;

  function updateTotals() {
    var subtotalEl = qs('#subtotal');
    var finalEl = qs('#final-total');
    var shipEl = qs('#shipping-fee');
    var couponEl = qs('#coupon-discount');
    var couponRow = qs('#coupon-row');
    var discountInput = qs('#input-discount-amount');

    var pointsSection = qs('#points-section');
    var pointsRow = qs('#points-row');
    var pointsDiscountEl = qs('#points-discount');
    var pointsInput = qs('#input-points-discount-amount');
    var pointsCheckbox = qs('#use-points');
    var pointsAvailableText = qs('#points-available-text');
    var totalsContainer = qs('.order-totals');

    if (!subtotalEl || !finalEl || !shipEl) return;
    var subtotal = parseAmount(subtotalEl.textContent);

    var isVirt = isVirtualCheckout();
    var fee = 0;
    if (!isVirt) {
      var selected = qs('input[name="shipping_method"]:checked');
      if (selected && selected.getAttribute('data-fee')) {
        fee = parseAmount(selected.getAttribute('data-fee'));
      } else {
        fee = parseAmount(shipEl.textContent);
      }
    }

    var discount = 0;
    if (couponRow && couponRow.style.display !== 'none' && couponEl && couponEl.textContent) {
      discount = parseAmount(couponEl.textContent);
    }
    if (discountInput) {
      discountInput.value = discount.toFixed(2);
    }

    // 计算符合 usable_categories 的商品小计金额
    var eligibleSubtotal = 0;
    var rawUsableCats = totalsContainer ? (totalsContainer.getAttribute('data-points-usable-categories') || '') : '';
    var usableCatList = rawUsableCats ? rawUsableCats.split(',').map(function(s) { return s.trim(); }).filter(Boolean) : [];
    
    var items = qsa('.order-item');
    if (items.length > 0) {
      items.forEach(function(item) {
        var catId = String(item.getAttribute('data-category-id') || '');
        var rawCatIds = String(item.getAttribute('data-category-ids') || catId);
        var itemCatList = rawCatIds.split(',').map(function(s) { return s.trim(); }).filter(Boolean);

        var itemPriceText = item.querySelector('.item-price') ? item.querySelector('.item-price').textContent : '';
        var itemTotal = parseAmount(item.getAttribute('data-item-total') || itemPriceText || '0');

        var isEligible = false;
        if (usableCatList.length === 0) {
          isEligible = true;
        } else if (itemCatList.length === 0) {
          // 若前端没有打上分类ID标签（如自定义异步渲染），默认兜底允许匹配
          isEligible = true;
        } else {
          for (var i = 0; i < itemCatList.length; i++) {
            if (usableCatList.indexOf(itemCatList[i]) !== -1) {
              isEligible = true;
              break;
            }
          }
        }

        if (isEligible) {
          eligibleSubtotal += itemTotal;
        }
      });
    } else {
      eligibleSubtotal = subtotal;
    }

    var CURRENCY_SYMBOL = (window.TODO && window.TODO.CURRENCY_SYMBOL) || '';
    var CURRENCY_RATE = (window.TODO && window.TODO.CURRENCY_RATE) || 1.0;

    // 前端 DOM 渲染的小计/优惠券均为当前显示币种，计算积分可抵金额需转回基准货币比对
    var eligibleSubtotalBase = (eligibleSubtotal / CURRENCY_RATE);
    var discountBase = (discount / CURRENCY_RATE);
    var remainingEligibleBase = Math.max(0, eligibleSubtotalBase - discountBase);

    var userPoints = parseFloat(totalsContainer ? (totalsContainer.getAttribute('data-user-points') || '0') : '0');
    var ratio = parseInt(totalsContainer ? (totalsContainer.getAttribute('data-points-ratio') || '100') : '100', 10);
    if (isNaN(ratio) || ratio <= 0) ratio = 100;

    // 基准货币下的可抵扣最高金额
    var maxPointsDiscountBase = userPoints / ratio;
    var actualPointsDiscountBase = Math.min(maxPointsDiscountBase, remainingEligibleBase);
    actualPointsDiscountBase = Math.round(actualPointsDiscountBase * 100) / 100;

    // 当前语言币种下的抵扣显示金额
    var displayActualPointsDiscount = (actualPointsDiscountBase * CURRENCY_RATE).toFixed(2);

    if (userPoints > 0 && remainingEligibleBase > 0 && actualPointsDiscountBase > 0) {
      if (pointsSection) pointsSection.style.display = 'block';
      if (pointsAvailableText) {
        pointsAvailableText.textContent = userPoints + ' ' + __t("积分，可抵") + ' ' + CURRENCY_SYMBOL + displayActualPointsDiscount;
      }
    } else {
      if (pointsSection) pointsSection.style.display = 'none';
      if (pointsCheckbox) pointsCheckbox.checked = false;
    }

    var pointsDiscountBase = 0;
    var pointsDiscountDisplay = 0;
    if (pointsCheckbox && pointsCheckbox.checked && pointsSection && pointsSection.style.display !== 'none') {
      pointsDiscountBase = actualPointsDiscountBase;
      pointsDiscountDisplay = actualPointsDiscountBase * CURRENCY_RATE;
      if (pointsRow) pointsRow.style.display = 'flex';
      if (pointsDiscountEl) pointsDiscountEl.textContent = '-' + CURRENCY_SYMBOL + pointsDiscountDisplay.toFixed(2);
    } else {
      if (pointsRow) pointsRow.style.display = 'none';
      if (pointsDiscountEl) pointsDiscountEl.textContent = '-' + CURRENCY_SYMBOL + '0.00';
    }
    if (pointsInput) {
      pointsInput.value = pointsDiscountBase.toFixed(2);
    }

    shipEl.textContent = CURRENCY_SYMBOL + fee.toFixed(2);

    var finalAmount = subtotal + fee - discount - pointsDiscountDisplay;
    if (finalAmount < 0) finalAmount = 0;

    finalEl.textContent = CURRENCY_SYMBOL + finalAmount.toFixed(2);

    // 更新账户余额支付状态和差价提示
    updateBalancePaymentState(finalAmount);
  }

  function updateBalancePaymentState(finalTotal) {
    var paymentContainer = qs('.payment-methods');
    var balanceRadio = qs('input[name="payment_method"][value="balance"]');
    if (!balanceRadio || !paymentContainer) return;

    var CURRENCY_SYMBOL = (window.TODO && window.TODO.CURRENCY_SYMBOL) || '';
    var userBalance = parseAmount(paymentContainer.getAttribute('data-user-balance') || '0');
    var diffTipEl = qs('.balance-diff-tip');
    var btnWrapEl = qs('.balance-recharge-btn-wrap');
    var methodItem = balanceRadio.closest('.payment-method');

    if (userBalance < finalTotal) {
      var diff = finalTotal - userBalance;
      if (diffTipEl) {
        diffTipEl.textContent = __t('再充值') + ' ' + CURRENCY_SYMBOL + diff.toFixed(2) + ' ' + __t('即可使用账户余额支付');
      }
      if (btnWrapEl) {
        btnWrapEl.style.display = 'block';
      }
      balanceRadio.disabled = true;
      if (methodItem) {
        methodItem.classList.add('is-disabled');
      }
      // 如果当前选中的是余额支付，自动切切换到其他可用的支付方式
      if (balanceRadio.checked) {
        balanceRadio.checked = false;
        var availableRadio = qs('input[name="payment_method"]:not([value="balance"]):not(:disabled)');
        if (availableRadio) {
          availableRadio.checked = true;
        }
      }
    } else {
      if (diffTipEl) {
        diffTipEl.textContent = '';
      }
      if (btnWrapEl) {
        btnWrapEl.style.display = 'none';
      }
      balanceRadio.disabled = false;
      if (methodItem) {
        methodItem.classList.remove('is-disabled');
      }
    }
  }

  function bindShippingChange() {
    qsa('input[name="shipping_method"]').forEach(function(radio) {
      radio.addEventListener('change', updateTotals);
    });
  }

  function bindCouponApply() {
    var btn = qs('#btn-apply-coupon');
    var input = qs('#coupon-code');
    var row = qs('#coupon-row');
    var discountEl = qs('#coupon-discount');
    var discountInput = qs('#input-discount-amount');
    var subtotalEl = qs('#subtotal');
    if (!btn || !input || !row || !discountEl) return;
    
    btn.addEventListener('click', function() {
      var code = input.value.trim();
      if (!code) {
        showToast(__t('请输入优惠券码'));
        return;
      }

      var subtotal = parseAmount(subtotalEl ? subtotalEl.textContent : '0');
      btn.disabled = true;
      var origBtnText = btn.textContent;
      btn.textContent = __t('校验中');

      var ajaxUrl = SITE_URL + '/includes/ajax/coupon-handler.php';
      var formData = new FormData();
      var form = qs('#checkout-form');
      formData.append('action', 'apply_coupon');
      formData.append('coupon_code', code);
      formData.append('subtotal', subtotal);

      if (form) {
        ['buy_now_mode', 'buy_now_product_id', 'buy_now_quantity', 'buy_now_specs', 'guest_email', 'recipient_phone'].forEach(function(name) {
          var field = form.querySelector('[name="' + name + '"]');
          if (field && field.value !== undefined && field.value !== null && field.value !== '') {
            formData.append(name, field.value);
          }
        });
      }

      fetch(ajaxUrl, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: formData
      })
      .then(function(res) {
        return res.json();
      })
      .then(function(data) {
        btn.disabled = false;
        btn.textContent = origBtnText;

        var CURRENCY_SYMBOL = (window.TODO && window.TODO.CURRENCY_SYMBOL) || '';
        if (data && data.success && data.data) {
          var disc = parseFloat(data.data.discount) || 0;
          var discFormat = data.data.display_discount_format || data.data.discount_format || disc.toFixed(2);
          
          row.style.display = 'flex';
          discountEl.textContent = '-' + CURRENCY_SYMBOL + discFormat;
          if (discountInput) discountInput.value = disc.toFixed(2);

          showToast(data.data.message || __t('兑换成功'), 1);
        } else {
          var errMsg = (data && data.message) ? data.message : __t('兑换失败');
          row.style.display = 'none';
          discountEl.textContent = '-' + CURRENCY_SYMBOL + '0.00';
          if (discountInput) discountInput.value = '0.00';

          showToast(errMsg, 2);
        }
        updateTotals();
      })
      .catch(function(err) {
        btn.disabled = false;
        btn.textContent = origBtnText;
        console.error('Error redeeming coupon:', err);
        showToast(__t('网络错误'), 2);
      });
    });
  }

  function bindAddressToggle() {
    var addBtn = qs('#add-new-address');
    var form = qs('.new-address-form');
    if (!addBtn || !form) return;
    addBtn.addEventListener('click', function() {
      form.style.display = (form.style.display === 'none' ? '' : 'none');
    });
  }

  function preventDoubleSubmit() {
    var form = qs('#checkout-form');
    if (!form) return;
    form.addEventListener('submit', function(e) {
      if (checkMixedProducts()) {
        e.preventDefault();
        showToast(__t('虚拟商品与实物商品不能同时下单，请分开结算'), 2);
        return false;
      }
      var agreementCheckbox = form.querySelector('input[name="agreement"]');
      if (agreementCheckbox && !agreementCheckbox.checked) {
        e.preventDefault();
        showToast(__t('请阅读并同意相关协议'), 2);
        if (typeof agreementCheckbox.focus === 'function') {
          agreementCheckbox.focus();
        }
        return false;
      }
      if (submitting) {
        e.preventDefault();
        return false;
      }
      submitting = true;
      e.preventDefault();
      
      // 提交订单
      submitOrder(form);
    });
  }
  
  // 规范化接口响应：兼容不同后端包装格式
  // - checkout-process.php 使用 todo_send_json_success，数据嵌套在 .data 下
  // - cart-handler.php buy_now 使用 todo_send_json，数据在顶层
  function normalizeResponse(data) {
    if (!data || typeof data !== 'object') return data;
    // 如果顶层有 data 字段且 success 在顶层，取 data 字段内容合并到顶层
    if (data.success && data.data && typeof data.data === 'object') {
      var merged = {};
      var k;
      for (k in data) { if (data.hasOwnProperty(k)) merged[k] = data[k]; }
      for (k in data.data) { if (data.data.hasOwnProperty(k) && !(k in merged)) merged[k] = data.data[k]; }
      // 确保 data 子对象内字段优先
      for (k in data.data) { if (data.data.hasOwnProperty(k)) merged[k] = data.data[k]; }
      return merged;
    }
    // cart-handler.php 的 success 响应也可能是 { success: true, data: { cart_count: ... } }
    // 这种不包装 order_id/redirect_url，仅返回 cart 信息，无需合并
    return data;
  }

  // 从响应中提取错误消息（兼容多种错误格式）
  function getErrorMsg(data) {
    return data.message || data.error || data.msg || __t('订单创建失败');
  }

  async function submitOrder(form) {
    var btn = qs('#submit-order');
    if (btn) { 
      btn.disabled = true; 
      btn.textContent = __t('正在提交'); 
    }
    
    try {
      // 收集表单数据
      var formData = new FormData(form);
      
      // 统一提交到 checkout-process.php
      var createToken = await window.todoCSRF.getToken('create_order');
      formData.set('_todononce', createToken);
      
      // 幂等键：服务端据此识别重复提交
      var submitKey = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() :
        'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
          var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16);
        });
      formData.set('_submit_key', submitKey);
      
      var checkoutUrl = (window.TODO && window.TODO.join) ? window.TODO.join((window.TODO.INCLUDES_AJAX || (SITE_URL + '/includes/ajax')), 'checkout-process.php') : (SITE_URL + '/includes/ajax/checkout-process.php');
      var response = await fetch(checkoutUrl, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-TODO-Nonce': createToken
        },
        body: formData
      });
      
      var data = normalizeResponse(await response.json());
      
      if (data.success) {
        var targetUrl = resolveTargetUrl(data);
        if (targetUrl) {
          window.location.href = targetUrl;
          return;
        }
      } else {
        throw new Error(getErrorMsg(data));
      }
    } catch (error) {
      console.error('Order submission failed:', error);
      showToast(__t('订单提交失败') + ': ' + error.message, 2);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = __t('提交订单');
      }
      submitting = false;
    }
  }

  // 根据响应数据解析目标跳转 URL
  function resolveTargetUrl(data) {
    // 优先使用后端返回的显式 redirect_url / payment_url（其在后端已精准处理了多语言前缀）
    if (data.redirect_url) {
      return data.redirect_url;
    }
    if (data.payment_url) {
      return data.payment_url;
    }
    if (data.payment_result) {
      if (data.payment_result.redirect_url) return data.payment_result.redirect_url;
      if (data.payment_result.payment_url) return data.payment_result.payment_url;
    }

    // 获取当前页面的多语言前缀（例如 /en）
    var pathPrefix = '';
    var m = window.location.pathname.match(/^(\/[a-z]{2}(?:-[a-zA-Z]{2,4})?)(?=\/|$)/i);
    if (m && m[1]) {
      pathPrefix = m[1];
    }

    // 有 order_id 则跳转到当前语言前缀下的支付页
    if (data.order_id) {
      var url = window.location.origin + pathPrefix + '/?user=payment&order_id=' + encodeURIComponent(data.order_id);
      var selectedPay = document.querySelector('input[name="payment_method"]:checked');
      if (selectedPay && selectedPay.value) {
        url += '&pay_method=' + encodeURIComponent(selectedPay.value);
      }
      return url;
    }
    // 最后回退到订单列表（携带语言前缀）
    return window.location.origin + pathPrefix + '/?user=orders';
  }

  function initCheckout() {
    if (checkMixedProducts()) {
      var btn = qs('#submit-order');
      if (btn) btn.disabled = true;
      showToast(__t('虚拟商品与实物商品不能同时下单，请分开结算'), 2);
    }
    if (isVirtualCheckout()) {
      updateVirtualState(true);
    }
    bindShippingChange();
    bindCouponApply();
    bindAddressToggle();
    
    var pointsCheckbox = qs('#use-points');
    if (pointsCheckbox) {
      pointsCheckbox.addEventListener('change', updateTotals);
    }

    updateTotals();
    preventDoubleSubmit();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCheckout);
  } else {
    initCheckout();
  }
})();
