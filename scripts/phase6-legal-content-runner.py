#!/usr/bin/env python3
"""Execute one bounded legal content update through MCP Preview -> Apply -> rendered Verify."""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import re
import sys
from pathlib import Path
from typing import Any

ROOT=Path(__file__).resolve().parents[1]
BASE_RUNNER=Path(__file__).with_name('phase2d-command-runner.py')
ACTION='legal.content.update'
ALLOWED_KEYS=('privacy-policy','legal-notice')
ALLOWED_LANGUAGES=('en','es')
PLAN_RE=re.compile(r'^erm-legal-plan-[a-f0-9]{20}-[a-f0-9]{8}$')
OP_RE=re.compile(r'^erm-legal-op-[a-f0-9]{32}$')

spec=importlib.util.spec_from_file_location('phase6_legal_base',BASE_RUNNER)
if spec is None or spec.loader is None: raise SystemExit('unable to load Phase 2D command runner')
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
CommandError=base.CommandError
sanitized_message=base.sanitized_message
write_result=base.write_result


def plain(value:Any,min_len:int,max_len:int)->bool:
    return isinstance(value,str) and min_len<=len(value.strip())<=max_len and '<' not in value and '>' not in value and '\x00' not in value


def validate_payload(payload:dict[str,Any])->None:
    if set(payload)!={'key','language','sections'}: raise CommandError('legal.content.update payload must contain only key, language and sections')
    if payload.get('key') not in ALLOWED_KEYS: raise CommandError('legal content key is outside the Privacy/Legal allowlist')
    if payload.get('language') not in ALLOWED_LANGUAGES: raise CommandError('legal content language must be en or es')
    sections=payload.get('sections')
    if not isinstance(sections,list) or not 2<=len(sections)<=6: raise CommandError('legal content requires between 2 and 6 sections')
    for section in sections:
        if not isinstance(section,dict) or set(section)!={'title','body'}: raise CommandError('each legal section must contain only title and body')
        if not plain(section.get('title'),1,120): raise CommandError('legal section title is outside bounds or contains markup')
        if not plain(section.get('body'),45,4000): raise CommandError('legal section body is outside bounds or contains markup')


def validate_command(command:dict[str,Any],registry:dict[str,Any]|None=None)->None:
    if set(command)!={'version','command_id','tenant','action','requested_by','payload'}: raise CommandError('command keys do not match command-v1 contract')
    if command.get('version')!='1': raise CommandError('unsupported command version')
    command_id=command.get('command_id')
    if not isinstance(command_id,str) or not base.COMMAND_ID_RE.fullmatch(command_id): raise CommandError('invalid command_id')
    if command.get('tenant')!='eduardo-research': raise CommandError('legal content tenant is not registered')
    if command.get('action')!=ACTION: raise CommandError('unsupported legal content action')
    if command.get('requested_by')!='chatgpt': raise CommandError('requested_by must be chatgpt')
    payload=command.get('payload')
    if not isinstance(payload,dict): raise CommandError('payload must be an object')
    validate_payload(payload)


def load_validated_command(path:Path)->dict[str,Any]:
    command=json.loads(path.read_text(encoding='utf-8'));validate_command(command);return command


def find_matching_id(value:Any,pattern:re.Pattern[str])->str|None:
    if isinstance(value,str) and pattern.fullmatch(value): return value
    if isinstance(value,dict):
        for item in value.values():
            found=find_matching_id(item,pattern)
            if found:return found
    if isinstance(value,list):
        for item in value:
            found=find_matching_id(item,pattern)
            if found:return found
    return None


def is_verified(value:Any)->bool:
    if isinstance(value,dict):
        if value.get('status')=='verified': return True
        if value.get('verified') is True: return True
        return any(is_verified(item) for item in value.values())
    if isinstance(value,list): return any(is_verified(item) for item in value)
    return False


def execute(command_path:Path,result_dir:Path)->int:
    command=load_validated_command(command_path);command_id=str(command['command_id']);existing=result_dir/f'{command_id}.json'
    if existing.exists(): print(f'ALREADY_COMPLETED {existing}');return 0
    token=os.environ.get('PHASE2D_MCP_TOKEN','').strip()
    if not token: raise CommandError('PHASE2D_MCP_TOKEN is required')
    base_url=os.environ.get('PHASE2D_MCP_BASE_URL',base.DEFAULT_BASE_URL).strip().rstrip('/')
    if base_url!=base.DEFAULT_BASE_URL: raise CommandError('legal content execution only permits the production MCP base URL')
    payload=command['payload'];tenant=str(command['tenant']);client=base.McpClient(f'{base_url}/{tenant}',token)
    plan_id=None;operation_id=None;rollback_ok=None
    try:
        client.initialize();client.call('research_capabilities')
        plan=client.call('site_legal_content_plan',{'key':payload['key'],'language':payload['language'],'sections':payload['sections'],'reason':f'Phase 6 legal acceptance {command_id}'})
        plan_id=find_matching_id(plan,PLAN_RE)
        if not plan_id: raise CommandError('legal content Preview did not return a valid exact plan ID')
        applied=client.call('site_legal_content_apply',{'planId':plan_id,'confirm':True})
        operation_id=find_matching_id(applied,OP_RE)
        if not operation_id: raise CommandError('legal content Apply did not return a valid operation ID')
        verification=client.call('site_legal_content_verify',{'operationId':operation_id,'rendered':True})
        if not is_verified(verification): raise CommandError('legal content rendered verification did not report verified=true')
        result={'version':'1','status':'success','tenant':tenant,'action':ACTION,'command_id':command_id,'verified':True,'resource':{'key':payload['key'],'language':payload['language'],'section_count':len(payload['sections'])},'correlation':{'plan_id':plan_id,'operation_id':operation_id}}
        path=write_result(result_dir,command_id,result);print(f'SUCCESS {path}');return 0
    except Exception as exc:  # noqa: BLE001
        if operation_id is not None:
            try:
                rolled=client.call('site_legal_content_rollback',{'operationId':operation_id,'confirm':True});rollback_ok=is_verified(rolled)
            except Exception: rollback_ok=False
        result={'version':'1','status':'failed','tenant':tenant,'action':ACTION,'command_id':command_id,'verified':False,'error':{'code':'execution_failed','message':'The bounded legal content lifecycle did not complete successfully.'},'correlation':{'plan_id':plan_id,'operation_id':operation_id},'rollback':{'attempted':operation_id is not None,'verified':rollback_ok}}
        path=write_result(result_dir,command_id,result);print(f'FAILED {path}: {sanitized_message(str(exc),token)}',file=sys.stderr);return 1
    finally: client.close()


def self_test()->int:
    good={'version':'1','command_id':'cmd-selftest-legal-content-0001','tenant':'eduardo-research','action':ACTION,'requested_by':'chatgpt','payload':{'key':'privacy-policy','language':'en','sections':[{'title':'Data handling','body':'This bounded fixture contains enough plain text to satisfy the legal content validation contract safely.'},{'title':'Contact','body':'This second bounded fixture also contains enough plain text for the exact validation contract.'}]}}
    validate_command(good)
    variants=[]
    bad=json.loads(json.dumps(good));bad['payload']['key']='about';variants.append(bad)
    bad=json.loads(json.dumps(good));bad['payload']['language']='fr';variants.append(bad)
    bad=json.loads(json.dumps(good));bad['payload']['sections']=bad['payload']['sections'][:1];variants.append(bad)
    bad=json.loads(json.dumps(good));bad['payload']['sections'][0]['body']='<b>markup is forbidden even when the body is long enough to pass the length boundary safely</b>';variants.append(bad)
    for candidate in variants:
        try:validate_command(candidate)
        except CommandError:continue
        raise CommandError('legal content self-test accepted an invalid command')
    # Exact CLI shape used by research-gateway-v2-worker.py must stay supported.
    positional=parse_cli(['commands/gateway-v2/requests/accepted.json','--result-dir','commands/gateway-v2/results'])
    assert positional.command==Path('commands/gateway-v2/requests/accepted.json')
    assert positional.result_dir==Path('commands/gateway-v2/results')
    legacy=parse_cli(['--command','commands/gateway-v2/requests/accepted.json','--result-dir','commands/gateway-v2/results'])
    assert legacy.command==positional.command and legacy.result_dir==positional.result_dir
    try:parse_cli(['a.json','--command','b.json'])
    except SystemExit as exc:
        assert exc.code==2
    else:raise CommandError('legal CLI accepted competing command selectors')
    print('PASS: Phase 6 legal content runner self-test');return 0


def parse_cli(argv:list[str]|None=None)->argparse.Namespace:
    parser=argparse.ArgumentParser()
    # The Gateway v2 dispatcher always passes the immutable request as argv[1].
    # Keep --command for older manual callers, without changing the MCP lifecycle.
    parser.add_argument('command',nargs='?',type=Path)
    parser.add_argument('--command',dest='legacy_command',type=Path)
    parser.add_argument('--result-dir',type=Path)
    parser.add_argument('--self-test',action='store_true')
    args=parser.parse_args(argv)
    if args.command is not None and args.legacy_command is not None:
        parser.error('provide the command path only once')
    args.command=args.command or args.legacy_command
    return args


def main()->int:
    args=parse_cli()
    if args.self_test:return self_test()
    if args.command is None or args.result_dir is None:
        raise CommandError('a command path and --result-dir are required')
    return execute(args.command,args.result_dir)

if __name__=='__main__':
    try:raise SystemExit(main())
    except CommandError as exc:print(f'ERROR: {exc}',file=sys.stderr);raise SystemExit(2)
