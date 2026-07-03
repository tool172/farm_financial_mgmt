/**
 * @file
 * Renders Chart.js charts for financial reports from drupalSettings.
 * drupalSettings.farmFinancialMgmt.charts = { canvasId: {chartjs config}, ... }
 */
(function (Drupal, drupalSettings, once) {
  'use strict';
  Drupal.behaviors.ffmReportCharts = {
    attach: function (context) {
      var charts = (drupalSettings.farmFinancialMgmt && drupalSettings.farmFinancialMgmt.charts) || {};
      Object.keys(charts).forEach(function (id) {
        var els = once('ffm-chart', '#' + id, context);
        if (!els.length || typeof Chart === 'undefined') {
          return;
        }
        new Chart(els[0], charts[id]);
      });
    }
  };
}(Drupal, drupalSettings, once));
