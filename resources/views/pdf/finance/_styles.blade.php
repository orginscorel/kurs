<style>
  @page { margin: 13mm 12mm 16mm; }
  * { font-family: "DejaVu Sans", sans-serif; }
  body { font-size: 8.8pt; color: #1b2233; margin: 0; }
  .navy { color: #13233f; }
  .muted { color: #6b7488; }
  .small { font-size: 7.6pt; }
  table { border-collapse: collapse; }
  .head { width: 100%; background: #13233f; color: #fff; }
  .head td { padding: 10px 12px; vertical-align: middle; }
  .head .inst { font-size: 13pt; font-weight: bold; letter-spacing: -0.2px; }
  .head .isub { font-size: 7.6pt; color: #c9d3e6; }
  .head .doc { text-align: right; }
  .head .dtitle { font-size: 11.5pt; font-weight: bold; letter-spacing: 1.2px; }
  .head .dno { font-size: 9pt; margin-top: 2px; }
  .head .logo { background: #fff; padding: 4px; border-radius: 4px; }
  .strip { height: 3px; background: #c9a227; }
  .box { width: 100%; margin-top: 10px; }
  .box td.cell { vertical-align: top; border: 1px solid #dfe4ee; padding: 7px 9px; }
  .box .lbl { font-size: 7pt; color: #6b7488; letter-spacing: 0.6px; margin-bottom: 2px; }
  .box .val { font-size: 9.2pt; font-weight: bold; color: #13233f; }
  table.grid { width: 100%; margin-top: 10px; }
  table.grid th { background: #eef1f7; color: #13233f; font-size: 7.4pt; letter-spacing: 0.3px; text-align: left; padding: 5px 5px; border-bottom: 1.5px solid #13233f; }
  table.grid th.r { text-align: right; }
  table.grid td { padding: 4.5px 5px; border-bottom: 1px solid #e6e9f0; vertical-align: top; }
  table.grid tr.alt td { background: #fafbfd; }
  table.grid tfoot td { font-weight: bold; background: #eef1f7; border-top: 1.5px solid #13233f; border-bottom: none; }
  .r { text-align: right; white-space: nowrap; }
  .c { text-align: center; }
  .neg { color: #b42335; }
  .pos { color: #1c7a4c; }
  .totals { width: 46%; margin-left: 54%; margin-top: 8px; }
  .totals td { padding: 4px 6px; border-bottom: 1px solid #e6e9f0; }
  .totals tr.grand td { background: #13233f; color: #fff; font-weight: bold; font-size: 10pt; border: none; }
  .words { margin-top: 8px; padding: 6px 9px; border-left: 3px solid #c9a227; background: #fbf8ee; font-size: 8.4pt; }
  .note { margin-top: 8px; padding: 6px 9px; border: 1px solid #dfe4ee; font-size: 7.8pt; color: #4a5368; }
  .doc { position: relative; }
  .doc.break { page-break-after: always; }
  .stamp { position: absolute; top: 260px; left: 0; right: 0; text-align: center; font-size: 44pt; font-weight: bold; opacity: 0.14; transform: rotate(-22deg); }
  .stamp.red { color: #b42335; }
  .stamp.gray { color: #6b7488; }
  .sign { width: 100%; margin-top: 26px; }
  .sign td { width: 50%; text-align: center; font-size: 8pt; color: #6b7488; padding-top: 26px; }
  .sign .line { border-top: 1px solid #b9c1d3; margin: 0 20px; padding-top: 4px; }
  .kpis { width: 100%; margin-top: 10px; }
  .kpis td { border: 1px solid #dfe4ee; padding: 6px 8px; vertical-align: top; }
  .kpis .k { font-size: 7pt; color: #6b7488; }
  .kpis .v { font-size: 11pt; font-weight: bold; color: #13233f; }
  h3.sec { font-size: 9.6pt; color: #13233f; margin: 14px 0 0; padding-bottom: 3px; border-bottom: 1px solid #dfe4ee; }
  .foot { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 6.8pt; color: #8a93a6; border-top: 1px solid #dfe4ee; padding-top: 3px; }
  .note-card { border: 1.4px solid #13233f; padding: 9px 12px; position: relative; }
  .note-card .ttl { font-size: 15pt; font-weight: bold; color: #13233f; letter-spacing: 3px; }
  .note-card .grid2 td { padding: 3px 4px; vertical-align: top; }
  .note-card .fld { border-bottom: 1px dotted #6b7488; min-width: 80px; display: inline-block; }
  .note-card .body { font-size: 8.6pt; line-height: 1.45; text-align: justify; margin: 6px 0; }
  .note-card .sig td { border: 1px solid #dfe4ee; padding: 5px 7px; vertical-align: top; font-size: 7.8pt; }
  .note-card .amountbox { border: 1.4px solid #13233f; padding: 3px 8px; font-weight: bold; font-size: 11pt; }
  .note-cut { border-top: 1px dashed #9aa3b5; margin: 7px 0; }
  .foot .pg:after { content: "Sayfa " counter(page); }
</style>
