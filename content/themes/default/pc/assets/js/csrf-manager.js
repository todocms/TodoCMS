(function(global){
  // 防重复加载：如果已存在 TodoCSRFManager，则只确保全局实例与兼容别名，不再重新声明
  if (global.TodoCSRFManager) {
    if (!global.todoCSRF) {
      try {
        global.todoCSRF = new global.TodoCSRFManager();
      } catch (e) {
        // 若构造失败，安全忽略，页面可能稍后再次创建
      }
    }
    // 兼容旧调用名（如部分页面使用 TodoCSRF.getToken）
    if (!global.TodoCSRF) {
      global.TodoCSRF = global.todoCSRF;
    }
    if (typeof module !== 'undefined' && module.exports) {
      module.exports = global.TodoCSRFManager;
    }
    return;
  }

  /**
   * TodoCMS CSRF令牌管理器
   * 用于前端获取和管理CSRF令牌
   */
  class TodoCSRFManager {
      constructor() {
          this.tokens = {};
          this.ajaxUrl = this.getAjaxUrl();
      }
      
      /**
       * 获取AJAX URL
       */
      getAjaxUrl() {
          // 优先使用全局 window.TODO.INCLUDES_AJAX
          if (global.TODO && global.TODO.INCLUDES_AJAX) {
              return String(global.TODO.INCLUDES_AJAX).replace(/\/+$/, '') + '/';
          }
          // 次选 site-root 元标签或 global.TODO.SITE_ROOT，确保无语言前缀
          const siteRoot = (global.TODO && global.TODO.SITE_ROOT)
              || document.querySelector('meta[name="site-root"]')?.getAttribute('content')
              || document.querySelector('meta[name="site-url"]')?.getAttribute('content')
              || global.location.origin;
          return siteRoot.replace(/\/+$/, '') + '/includes/ajax/';
      }
      
      /**
       * 获取CSRF令牌
       * @param {string} action - 动作名称
       * @returns {Promise<string>} - 返回令牌
       */
      async getToken(action) {
          // 如果已有缓存的令牌，直接返回
          if (this.tokens[action]) {
              return this.tokens[action];
          }
          
          try {
              const response = await fetch(this.ajaxUrl + 'cart-handler.php', {
                  method: 'POST',
                  headers: {
                      'Content-Type': 'application/x-www-form-urlencoded',
                      'X-Requested-With': 'XMLHttpRequest'
                  },
                  body: 'cart_action=' + encodeURIComponent('csrf_token') + '&action=' + encodeURIComponent(action)
              });
              
              const data = await response.json();
              
              const token = data && data.success ? (data.token || (data.data && data.data.token)) : null;
              if (token) {
                  this.tokens[action] = token;
                  return token;
              } else {
                  throw new Error(data.message || __t('获取CSRF令牌失败'));
              }
          } catch (error) {
              console.error('CSRF Token Error:', error);
              return null;
          }
      }
      
      /**
       * 刷新令牌
       * @param {string} action - 动作名称
       */
      async refreshToken(action) {
          delete this.tokens[action];
          return await this.getToken(action);
      }
      
      /**
       * 清除所有令牌
       */
      clearTokens() {
          this.tokens = {};
      }
      
      /**
       * 为表单添加CSRF令牌字段
       * @param {HTMLFormElement} form - 表单元素
       * @param {string} action - 动作名称
       */
      async addTokenToForm(form, action) {
          const token = await this.getToken(action);
          
          // 移除已存在的令牌字段
          const existingToken = form.querySelector('input[name="_todononce"]');
          if (existingToken) {
              existingToken.remove();
          }
          
          // 添加新的令牌字段
          const tokenInput = document.createElement('input');
          tokenInput.type = 'hidden';
          tokenInput.name = '_todononce';
          tokenInput.value = token;
          form.appendChild(tokenInput);
      }
      
      /**
       * 为FormData添加CSRF令牌
       * @param {FormData} formData - FormData对象
       * @param {string} action - 动作名称
       */
      async addTokenToFormData(formData, action) {
          const token = await this.getToken(action);
          formData.append('_todononce', token);
      }
  }

  // 暴露到全局并创建实例（带兼容别名）
  global.TodoCSRFManager = TodoCSRFManager;
  if (!global.todoCSRF) {
    global.todoCSRF = new TodoCSRFManager();
  }
  if (!global.TodoCSRF) {
    global.TodoCSRF = global.todoCSRF;
  }

  // 导出类（如果使用模块系统）
  if (typeof module !== 'undefined' && module.exports) {
      module.exports = TodoCSRFManager;
  }
})(window);
