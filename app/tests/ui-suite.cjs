'use strict';
const path=require('node:path');
const {spawnSync}=require('node:child_process');
for(const filename of ['ui-framing.cjs','ui-wallet.cjs','ui-confirm.cjs','ui-duplex-geometry.cjs','ui-dashboard.cjs','ui-employee-search.cjs']){
  const result=spawnSync(process.execPath,[path.join(__dirname,filename)],{stdio:'inherit',env:process.env});
  if(result.status!==0){process.exitCode=result.status||1;break}
}
