#!/usr/bin/env python3
"""Additional role and product boundaries; writes only disposable QA test data."""
import argparse, copy, importlib.util, secrets
from pathlib import Path
spec = importlib.util.spec_from_file_location('qa_base', Path(__file__).with_name('integration.py'))
qa = importlib.util.module_from_spec(spec); spec.loader.exec_module(qa)
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--private-dir', required=True)
parser.add_argument('--base', default='http://127.0.0.1:8080')
parser.add_argument('--db-container', default='dezhawo-qa-db')
parser.add_argument('--web-container', default='dezhawo-qa-web')
args = parser.parse_args()
safety = qa.Checks(args)  # validates loopback URL, actual *_qa DB and private credentials
secret = safety.secrets
base = args.base
prefix = 'adminqa_' + secrets.token_hex(5)
owner = qa.Client(base); owner.bootstrap()
owner.action('auth.adminLogin', {'phone': secret['owner_phone'], 'password': secret['owner_password']})
owner_id = owner.bootstrap()['currentUser']['id']
checks = 0

def check(action, actor, value, expected=200):
    global checks
    result = actor.action(action, value, expected=expected)
    checks += 1
    return result

def role(label, permissions):
    return check('role.save', owner, {'role': {'name': prefix+' '+label, 'permissions': permissions}})['id']

def staff(role_id):
    phone='09'+''.join(str(secrets.randbelow(10)) for _ in range(9)); password=secrets.token_urlsafe(20)
    result=check('user.create',owner,{'name':prefix,'phone':phone,'roleId':role_id,'password':password})
    client=qa.Client(base); client.bootstrap(); check('auth.adminLogin',client,{'phone':phone,'password':password})
    return result['id'],client,phone

limited_role=role('محدود',['users.edit','roles.edit'])
rich_role=role('مالی',['finance.view'])
editor_role=role('محصول',['products.view','products.edit'])
limited_id,limited,_=staff(limited_role)
editor_id,editor,editor_phone=staff(editor_role)
check('user.role',limited,{'userId':limited_id,'roleId':rich_role},403)
check('user.role',limited,{'userId':owner_id,'roleId':'customer'},403)
check('user.enabled',limited,{'userId':owner_id,'enabled':False},403)
check('user.password',limited,{'userId':owner_id,'password':secrets.token_urlsafe(20)},403)
check('role.save',limited,{'role':{'name':prefix+' escalation','permissions':['finance.view']}},403)
check('role.delete',limited,{'id':limited_role,'replacementId':rich_role},403)
pid=prefix+'_p'; vid=prefix+'_v'
product={'id':pid,'title':prefix+' محصول','category':'case','price':500,'cost':100,'art':'case','badge':'','description':'','enabled':True,'variants':[{'id':vid,'name':'مشکی','color':'#222222','stock':3,'enabled':True}]}
check('product.save',owner,{'product':product})
edit=copy.deepcopy(product);edit.pop('cost');edit['title'] += ' ویرایش'
check('product.save',editor,{'product':edit})
bad=copy.deepcopy(edit);bad['cost']=200
check('product.save',editor,{'product':bad},403)
bad=copy.deepcopy(edit);bad['variants'][0]['stock']=1
check('product.save',editor,{'product':bad},403)
check('product.enabled',editor,{'id':pid,'enabled':False},403)
assert not editor.bootstrap()['users']
assert all('cost' not in p for p in editor.bootstrap()['products'])
checks += 2
guest=qa.Client(base);g=guest.bootstrap()
assert not g['users'] and not g['roles'] and not g['orders'] and not g['tickets'] and not g['movements']
assert all('cost' not in p for p in g['products']); checks += 2
check('cart.add',guest,{'productId':pid,'variantId':vid,'quantity':1})
reservation=check('reservation.create',guest,{})
assert all('unitCost' not in line for line in reservation['lines']); checks+=1
bad=copy.deepcopy(product);bad['variants'][0]['stock']=0
check('product.save',owner,{'product':bad},409)
bad=copy.deepcopy(product);bad['variants'][0]['enabled']=False
check('product.save',owner,{'product':bad},409)
check('product.enabled',owner,{'id':pid,'enabled':False},409)
check('product.delete',owner,{'id':pid},409)
check('variant.enabled',owner,{'productId':pid,'variantId':vid,'enabled':False},409)
check('reservation.release',guest,{})
product['variants'].append({'id':prefix+'_v2','name':'سفید','color':'#ffffff','stock':0,'enabled':True})
check('product.save',owner,{'product':product})
removed=copy.deepcopy(product);removed['variants']=removed['variants'][1:]
check('product.save',owner,{'product':removed})
boot=owner.bootstrap();stored=next(p for p in boot['products'] if p['id']==pid)
assert any(v['id']==vid and not v['enabled'] for v in stored['variants']);checks+=1
assert stored['cost']==100;checks+=1
foreign=copy.deepcopy(removed);foreign['id']=prefix+'_other'
check('product.save',owner,{'product':foreign},400)
new_password=secrets.token_urlsafe(20)
check('user.password',owner,{'userId':editor_id,'password':new_password})
assert editor.bootstrap()['currentUser'] is None;checks+=1
check('auth.adminLogin',editor,{'phone':editor_phone,'password':new_password})
check('user.role',owner,{'userId':editor_id,'roleId':'customer'})
assert editor.bootstrap()['currentUser'] is None;checks+=1
check('user.role',owner,{'userId':editor_id,'roleId':'owner'},400)
print(f'Admin actual PHP/MariaDB regression checks passed: {checks}')
