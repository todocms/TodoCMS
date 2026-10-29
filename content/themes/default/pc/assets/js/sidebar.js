// 侧边栏JavaScript功能
$(document).ready(function() {
    // 侧边栏搜索表单提交
    $('.search-form').on('submit', function(e) {
        var keyword = $(this).find('.search-input').val().trim();
        if (!keyword) {
            e.preventDefault();
            alert(__t('请输入搜索关键词'));
            return false;
        }
    });
    
    // 标签云随机颜色
    $('.tag-cloud-item').each(function() {
        var colors = ['#3498db', '#e74c3c', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#34495e'];
        var randomColor = colors[Math.floor(Math.random() * colors.length)];
        $(this).css('background-color', randomColor);
    });
});