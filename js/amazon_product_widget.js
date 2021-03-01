/**
 * @file
 * Load amazon product widgets.
 */
(function () {

  /**
   * Loads amazon widget via Ajax.
   *
   * @param field
   */
  var loadAmazonWidget = function (field) {
    if (field.innerHTML.trim() === "") {
      var xhr = new XMLHttpRequest();
      xhr.open('GET', '/api/amazon/product?entity_id=' + field.dataset.entityId + '&entity_type=' + field.dataset.entityType + '&field=' + field.dataset.field + '&node_id=' + field.dataset.nodeId, true);
      xhr.onload = function() {
        if (xhr.status === 200) {
          var data = JSON.parse(xhr.response);
          if (data.count > 0) {
            field.style.display = 'block';
            field.innerHTML = data.content;
          }
        }
      };
      xhr.send();
    }
    else {
      field.style.display = 'block';
    }
  };

  document.addEventListener("DOMContentLoaded", function() {
    let fields = document.querySelectorAll('.amazon-product-widget');
    for (var i = 0; i < fields.length; i++ ) {
      loadAmazonWidget(fields[i]);
    }
  });

}());
