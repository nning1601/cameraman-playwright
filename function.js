// ประเภทที่ 1: ไม่รับค่าและไม่คืนค่า
function showName(){
    console.log('Tarairat');
}
showName();

// ประเภทที่ 2: รับค่าแต่ไม่คืนค่า
function showInfo(lastname, age){
    console.log(lastname);
    console.log(age);
}
showInfo("Ning", 17);

// ประเภทที่ 3: ไม่รับค่าแต่คืนค่า
function sumNumber(){
    return 2 + 3;
}
var total = sumNumber();
console.log(total);
console.log(sumNumber());

// ประเภทที่ 4: รับค่าและคืนค่า
function getFullName(firstname, lastname){
    return firstname + " " + lastname;
}
var fullname = getFullName("Sakchai", "Aodngam");
console.log(fullname);
